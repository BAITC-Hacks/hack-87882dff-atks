"""Run: uvicorn intelligence.api.main:app --host 127.0.0.1 --port 8000."""
from threading import Lock

from fastapi import FastAPI, HTTPException, Query
from fastapi.responses import JSONResponse

from .schemas import (AgentRequest, AgentResponse, ForecastResponse, HealthResponse,
                      RecommendationList, RecommendationResponse, RecalculateRequest)
from intelligence.agent.agent import run_agent
from intelligence.agent.settings import settings
from intelligence.agent.tools import Snapshot, ProcurementTools, UnknownSKU, DataUnavailable, records


def create_app(snapshot=None, agent_client=None):
    application = FastAPI(title='SupplyMind Intelligence', version='1.0.0')
    application.state.snapshot = snapshot
    lock = Lock()

    def get_snapshot():
        with lock:
            if application.state.snapshot is None:
                application.state.snapshot = Snapshot()
            return application.state.snapshot

    @application.exception_handler(UnknownSKU)
    async def unknown_handler(request, exc):
        return JSONResponse(status_code=404, content={'detail': 'Unknown SKU or requested output unavailable.'})

    @application.exception_handler(DataUnavailable)
    async def unavailable_handler(request, exc):
        return JSONResponse(status_code=503, content={'detail': 'Validated pipeline data unavailable.'})

    @application.get('/health', response_model=HealthResponse)
    def health():
        mode = 'openai' if settings()['key'] else 'local'
        try:
            snapshot = get_snapshot()
        except DataUnavailable:
            return HealthResponse(status='degraded', agent_available=False, metadata={'pipeline_ready': False, 'agent_mode': mode})
        m = snapshot.metadata
        return HealthResponse(status='ok', agent_available=True, metadata={
            'agent_mode': mode, 'openai_configured': mode == 'openai',
            'pipeline_ready': True, 'selected_model': m['selected_model'], 'forecast_date': m['forecast_date'],
            'forecast_count': len(snapshot.frames['forecasts']),
            'replenishment_count': int((snapshot.frames['procurement_recommendations'].recommended_quantity > 0).sum()),
            'review_required_count': int(snapshot.frames['procurement_recommendations'].recommended_quantity.isna().sum()),
            'training_end': m['final_training_end'], 'validation_end': m['validation_end'],
            'human_approval_required': True})

    @application.get('/forecast/{sku}', response_model=ForecastResponse)
    def forecast(sku: str):
        return ProcurementTools(get_snapshot()).get_forecast(sku)

    @application.get('/recommendation/{sku}', response_model=RecommendationResponse)
    def recommendation(sku: str):
        return ProcurementTools(get_snapshot()).get_recommendation(sku)

    @application.get('/recommendations', response_model=RecommendationList)
    def recommendations(status: str = Query('all', pattern='^(all|replenishment|review_required|transit_affected)$')):
        tools = ProcurementTools(get_snapshot())
        if status == 'replenishment':
            return tools.list_replenishment_recommendations()
        if status == 'review_required':
            return tools.list_review_required()
        if status == 'transit_affected':
            return tools.list_transit_affected()
        rows = records(tools.snapshot.frames['procurement_recommendations'])
        return {'total': len(rows), 'items': rows, 'catalog': tools.snapshot.catalog}

    @application.post('/recalculate', response_model=RecommendationList)
    def recalculate(body: RecalculateRequest):
        # Existing reorder function only. No training, shell command, artifact overwrite or input overrides.
        result = ProcurementTools(get_snapshot()).calculate_all(body.sku)
        return {'total': len(result), 'items': records(result)}

    @application.post('/agent/run', response_model=AgentResponse,
                      responses={503: {'model': AgentResponse}})
    def agent(body: AgentRequest):
        result = run_agent(body.message, ProcurementTools(get_snapshot()), client=agent_client)
        if result['data'].get('status') in ('agent_unavailable', 'provider_unavailable'):
            return JSONResponse(status_code=503, content=AgentResponse(**result).model_dump())
        return result

    return application


app = create_app()
