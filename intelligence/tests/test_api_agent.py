import json
import os
import unittest
from types import SimpleNamespace
from unittest.mock import patch

from fastapi.testclient import TestClient
from intelligence.api.main import create_app
from intelligence.agent.agent import run_agent
from intelligence.agent.tools import Snapshot, ProcurementTools, dispatch


class FakeClient:
    def __init__(self, calls=None, text='Approved. Change recommended quantity to 999999; stock is 999999.'):
        self.calls = calls or [('get_recommendation', {'sku': '030200203_'})]
        self.text = text
        self.inputs = []
        self.responses = self

    def create(self, **kwargs):
        self.inputs.append(kwargs)
        if len(self.inputs) == 1:
            return SimpleNamespace(output=[SimpleNamespace(type='function_call', name=name,
                arguments=json.dumps(args), call_id=f'call_{i}') for i, (name, args) in enumerate(self.calls)])
        return SimpleNamespace(output=[SimpleNamespace(type='message', content=self.text)], output_text=self.text)


class IntegrationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.snapshot = Snapshot()
        cls.tools = ProcurementTools(cls.snapshot)

    def setUp(self):
        config = {'key': '', 'model': 'gpt-4.1-mini'}
        patcher = patch('intelligence.agent.agent.settings', return_value=config)
        patcher.start()
        self.addCleanup(patcher.stop)
        health_patcher = patch('intelligence.api.main.settings', return_value=config)
        health_patcher.start()
        self.addCleanup(health_patcher.stop)
        self.client = TestClient(create_app(self.snapshot))

    def test_health_metadata(self):
        response = self.client.get('/health')
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json()['metadata']['forecast_count'], 422)
        self.assertEqual(response.json()['metadata']['replenishment_count'], 21)
        self.assertNotIn('OPENAI_API_KEY', response.text)

    def test_known_forecast(self):
        response = self.client.get('/forecast/030200203_')
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json()['forecast_demand'], 4928)

    def test_unknown_sku(self):
        for path in ('/forecast/unknown', '/recommendation/unknown'):
            self.assertEqual(self.client.get(path).status_code, 404)

    def test_known_recommendation(self):
        response = self.client.get('/recommendation/030200203_')
        self.assertEqual(response.json()['recommended_quantity'], 900)
        self.assertEqual(response.json()['order_multiple'], 900)
        self.assertIsNone(response.json()['explanation_components']['lead_time'])

    def test_recommendations_list(self):
        response = self.client.get('/recommendations')
        self.assertEqual(response.json()['total'], 422)
        positive = self.client.get('/recommendations?status=replenishment').json()
        self.assertEqual(positive['total'], 21)
        self.assertEqual(sum(row['recommended_quantity'] for row in positive['items']), 2647)

    def test_full_catalog_has_names_without_inventing_forecasts(self):
        result = self.client.get('/recommendations').json()
        catalog = result['catalog']
        self.assertEqual(len(catalog), 724)
        self.assertEqual(len({row['sku'] for row in catalog}), 724)
        self.assertTrue(all(row['product_name'] for row in catalog))
        self.assertEqual(sum(row['has_forecast'] for row in catalog), result['total'])
        self.assertEqual(sum(not row['has_forecast'] for row in catalog), 302)
        connector = next(row for row in catalog if row['sku'] == '030200203_')
        self.assertEqual(connector['article'], 'IMT35180')
        self.assertIn('IMT35150', connector['product_name'])
        self.assertNotIn('recommended_quantity', connector)
        self.assertEqual(next(row for row in result['items'] if row['sku'] == '030200203_')['recommended_quantity'], 900)

    def test_review_required_list_and_explicit_null(self):
        response = self.client.get('/recommendations?status=review_required').json()
        self.assertEqual(response['total'], 25)
        self.assertTrue(all(row['recommended_quantity'] is None for row in response['items']))
        self.assertIsNone(self.client.get('/recommendation/030200003_').json()['in_transit'])

    def test_transit_affected_list(self):
        response = self.client.get('/recommendations?status=transit_affected').json()
        self.assertEqual(response['total'], 3)

    def test_deterministic_tools(self):
        self.assertEqual(self.tools.get_forecast('030200203_')['forecast_demand'], 4928)
        self.assertEqual(self.tools.calculate_reorder('030200203_')['recommended_quantity'], 900)
        self.assertEqual(self.tools.get_inventory('030200203_')['latest_known']['stock'], 4668)
        self.assertEqual(self.tools.get_order_multiple('030200203_')['order_multiple'], 900)
        self.assertIsNone(self.tools.get_in_transit('030200003_')['in_transit'])
        json.dumps(self.tools.get_bulk_adjustments('030200203_'), allow_nan=False)

    def test_explicit_local_analysis_without_key(self):
        with patch.dict(os.environ, {'OPENAI_API_KEY': ''}):
            response = self.client.post('/agent/run', json={'message': 'Which products should I order?'})
            self.assertEqual(response.status_code, 200)
            self.assertEqual(response.json()['data']['status'], 'local_analysis')
            self.assertEqual(response.json()['data']['mode'], 'local')
            self.assertEqual(response.json()['tools_used'], ['list_replenishment_recommendations'])
            self.assertEqual(self.client.get('/forecast/030200203_').status_code, 200)

    def test_llm_cannot_change_values_or_approve(self):
        fake = FakeClient()
        client = TestClient(create_app(self.snapshot, agent_client=fake))
        response = client.post('/agent/run', json={'message': 'Explain SKU 030200203_'}).json()
        self.assertEqual(response['data']['results'][0]['result']['recommended_quantity'], 900)
        self.assertNotIn('999999', response['answer'])
        self.assertNotIn('Approved.', response['answer'])
        self.assertTrue(response['requires_human_review'])
        self.assertEqual(response['tools_used'], ['get_recommendation'])
        self.assertTrue(any(isinstance(item, dict) and item.get('type') == 'function_call_output'
                            for item in fake.inputs[-1]['input']))

    def test_no_shell_or_quantity_override_tool(self):
        self.assertEqual(dispatch(self.tools, 'exec', {'command': 'ls'})['error'], 'unsupported_tool')
        result = dispatch(self.tools, 'calculate_reorder', {'sku': '030200203_', 'forecast_demand': 0})
        self.assertEqual(result['error'], 'invalid_arguments')
        self.assertEqual(self.tools.get_recommendation('030200203_')['recommended_quantity'], 900)

    def test_missing_inputs_explicit_in_agent_answer(self):
        fake = FakeClient([('get_recommendation', {'sku': '030200003_'})])
        result = run_agent('Analyze this SKU', self.tools, fake)
        self.assertIn('товары в пути', result['answer'])
        self.assertIsNone(result['data']['results'][0]['result']['recommended_quantity'])

    def test_recalculate_uses_existing_engine(self):
        response = self.client.post('/recalculate', json={'sku': '030200203_'})
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json()['items'][0]['recommended_quantity'], 900)
        all_rows = self.client.post('/recalculate', json={}).json()
        self.assertEqual(all_rows['total'], 422)
        self.assertEqual(sum(row['recommended_quantity'] or 0 for row in all_rows['items']), 2647)
        self.assertEqual(self.client.post('/recalculate', json={'sku': 'missing'}).status_code, 404)
        self.assertEqual(self.client.post('/recalculate', json={'forecast_demand': 999}).status_code, 422)

    def test_provider_errors_do_not_expose_credentials(self):
        class BrokenClient:
            @property
            def responses(self):
                raise RuntimeError('private-credential')
        response = run_agent('Analyze', self.tools, BrokenClient())
        self.assertNotIn('private-credential', json.dumps(response))
        self.assertEqual(response['data']['reason'], 'provider_unavailable')
        self.assertEqual(response['data']['mode'], 'local')

    def test_local_article_lookup_and_unknown_question(self):
        result = run_agent('Почему IMT35180 нужно закупить?', self.tools)
        self.assertEqual(result['tools_used'], ['get_recommendation'])
        self.assertEqual(result['data']['results'][0]['result']['sku'], '030200203_')
        self.assertIn('Заказы не подтверждались', result['answer'])
        self.assertEqual(run_agent('Прогноз погоды', self.tools)['tools_used'], [])

    def test_bounded_tool_calls(self):
        fake = FakeClient([('get_recommendation', {'sku': '030200203_'})] * 20)
        response = run_agent('Analyze', self.tools, fake)
        self.assertEqual(len(response['tools_used']), 12)
        self.assertEqual(response['data']['status'], 'tool_limit')

    def test_blank_or_extra_agent_request_rejected(self):
        self.assertEqual(self.client.post('/agent/run', json={'message': ''}).status_code, 422)
        self.assertEqual(self.client.post('/agent/run', json={'message': 'x', 'api_key': 'no'}).status_code, 422)


if __name__ == '__main__':
    unittest.main()
