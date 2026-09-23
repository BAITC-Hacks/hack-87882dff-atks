import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../web/intelligence.js', import.meta.url), 'utf8');
const { intelligencePage, intelligenceDetail, catalogItems, recommendationText } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const item = { sku: '0001_<script>', current_stock: 4, in_transit: null, forecast_demand: 7.5, recommended_quantity: null, urgency: 'review_required', model_name: 'fixture', explanation_components: { issues: ['missing_or_invalid_in_transit'] } };
const snapshot = { results: { total: 1, items: [item] }, health: { agent_available: false }, fetched_at: '2026-09-23T00:00:00Z', stale: false };
const options = { query: '', filter: 'all', loading: false, error: '', agent: { message: '', busy: false, error: '', result: null } };
for (const page of ['dashboard', 'forecast', 'procurement', 'agent']) {
  const html = intelligencePage(page, snapshot, options);
  assert.ok(html.includes('Только просмотр'));
  assert.ok(!html.includes('data-action="review-group"'));
  assert.ok(!html.includes('<script>'));
  assert.ok(!html.includes('Python'));
  assert.ok(!html.includes('ceil('));
}
const detail = intelligenceDetail(item);
assert.ok(detail.includes('Нет данных'));
assert.ok(detail.includes('7,5'));
assert.ok(detail.includes('Нужно уточнить: товары в пути'));
assert.ok(intelligencePage('procurement', snapshot, { ...options, filter: 'replenishment' }).includes('Нет подходящих позиций'));
assert.ok(intelligencePage('forecast', snapshot, { ...options, query: 'missing' }).includes('Нет подходящих позиций'));
assert.ok(intelligencePage('forecast', { ...snapshot, stale: true, error: 'offline' }, options).includes('Сохранённая копия'));
assert.ok(intelligencePage('forecast', null, { ...options, error: '<script>offline</script>' }).includes('&lt;script&gt;offline'));
assert.ok(intelligencePage('agent', snapshot, options).includes('type="submit" disabled'));
const agent = { ...options.agent, result: { answer: '<script>unsafe</script>', tools_used: ['get_recommendation'], data: {}, requires_human_review: true } };
assert.ok(intelligencePage('agent', snapshot, { ...options, agent }).includes('&lt;script&gt;unsafe'));
const catalogSnapshot = { ...snapshot, results: { ...snapshot.results, catalog: [
  { sku: item.sku, product_name: 'Соединитель <script>', article: 'IMT-1', has_forecast: true },
  { sku: '0002_', product_name: 'Розетка', article: 'ATN-2', has_forecast: false },
] } };
const rows = catalogItems(catalogSnapshot);
assert.equal(rows.length, 2);
assert.equal(rows.find(row => row.sku === '0002_').forecast_demand, null);
assert.equal(rows.find(row => row.sku === '0002_').recommended_quantity, null);
assert.equal(rows.find(row => row.sku === item.sku).forecast_demand, 7.5);
const productPage = intelligencePage('products', catalogSnapshot, options);
assert.ok(productPage.includes('Показано 2 из 2 товаров'));
assert.ok(productPage.includes('Соединитель &lt;script&gt;'));
assert.ok(!productPage.includes('Соединитель <script>'));
assert.ok(intelligencePage('products', catalogSnapshot, { ...options, query: 'Розетка' }).includes('Показано 1 из 2 товаров'));
assert.ok(intelligencePage('products', catalogSnapshot, { ...options, query: 'ATN-2' }).includes('Показано 1 из 2 товаров'));
assert.ok(intelligencePage('products', catalogSnapshot, { ...options, filter: 'missing' }).includes('Показано 1 из 2 товаров'));
assert.ok(intelligencePage('products', catalogSnapshot, { ...options, filter: 'review_required' }).includes('Показано 1 из 2 товаров'));
const explanation = recommendationText({ ...item, recommended_quantity: 900, urgency: 'replenishment', net_requirement: 260, order_multiple: 900 });
assert.ok(explanation.includes('не хватает 260'));
assert.ok(explanation.includes('кратен 900'));
assert.ok(explanation.includes('рекомендовано 900'));
console.log('Intelligence UI rendering, filters, null values, stale state, agent state and escaping passed.');
const localSnapshot = { ...snapshot, health: { agent_available: true, metadata: { agent_mode: 'local' } } };
const localHtml = intelligencePage('agent', localSnapshot, options);
assert.ok(localHtml.includes('Локальный разбор, без OpenAI'));
assert.ok(!localHtml.includes('type="submit" disabled'));
assert.ok(intelligencePage('agent', { ...localSnapshot, stale: true }, options).includes('type="submit" disabled'));
assert.ok(intelligencePage('procurement', snapshot, options).includes('Поставщик не указан'));
assert.ok(intelligenceDetail({ ...item, effective_model_name: 'seasonal_trend', annual_growth_factor: 1.2, historical_lost_demand: 50 }).includes('Коэффициент годового роста'));
const module = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
assert.equal(module.filteredItems(catalogSnapshot, 'ATN-2', 'missing').length, 1);
const csv = module.procurementCsv([{ ...item, product_name: '=HYPERLINK("danger")', sku: '0001_', article: '@attack' }], snapshot);
assert.ok(csv.startsWith('\ufeff'));
assert.ok(csv.includes("'=HYPERLINK"));
assert.ok(csv.includes('""danger""'));
assert.ok(csv.includes("'@attack"));
assert.ok(csv.includes('"Не указан"'));
assert.ok(csv.includes('"0001_"'));
assert.ok(module.procurementCsv([item], { ...snapshot, stale: true }).includes('Сохранённая копия; требуется обновление'));
console.log('Assistant modes, supplier gaps, seasonal explanation and CSV export passed.');
