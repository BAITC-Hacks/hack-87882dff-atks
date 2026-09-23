from typing import Any, Literal
from pydantic import BaseModel, ConfigDict, Field


class ForecastResponse(BaseModel):
    sku: str
    forecast_date: str
    forecast_demand: float
    model_name: str
    effective_model_name: str | None = None
    last_observed_demand: float | None = None
    rolling_mean_3: float | None = None
    trend: float | None = None
    bulk_quantity_excluded: float | None = None
    partial_latest_month_excluded: bool = False
    historical_lost_demand: float | None = None
    seasonal_reference_demand: float | None = None
    annual_growth_factor: float | None = None
    demand_basis: str | None = None


class RecommendationResponse(ForecastResponse):
    current_stock: float | None
    stock_date: str | None = None
    in_transit: float | None
    available_stock: float | None
    net_requirement: float | None
    order_multiple: float | None
    recommended_quantity: float | None
    urgency: str
    explanation_components: dict[str, Any]
    transit_reduced_order: bool = False
    rounding_increased_order: bool = False


class CatalogItem(BaseModel):
    sku: str
    product_name: str | None
    article: str | None
    unit: str | None
    has_forecast: bool


class RecommendationList(BaseModel):
    total: int
    items: list[RecommendationResponse]
    catalog: list[CatalogItem] | None = None


class HealthResponse(BaseModel):
    status: Literal['ok', 'degraded']
    service: str = 'SupplyMind intelligence'
    agent_available: bool
    metadata: dict[str, Any]


class AgentRequest(BaseModel):
    model_config = ConfigDict(extra='forbid')
    message: str = Field(min_length=1, max_length=4000)


class AgentResponse(BaseModel):
    answer: str
    tools_used: list[str]
    data: dict[str, Any]
    requires_human_review: bool


class RecalculateRequest(BaseModel):
    model_config = ConfigDict(extra='forbid')
    sku: str | None = Field(default=None, min_length=1, max_length=128)
