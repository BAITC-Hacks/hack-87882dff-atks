<?php
declare(strict_types=1);

use RedBeanPHP\R;
use SmartStock\ApiException;
use SmartStock\IntelligenceApiService;
use SmartStock\IntelligenceResultsService;

// Included by supplymind-workflow.php only inside its disposable test database.
if (!isset($database) || !preg_match('/^supplymind_test_[a-f0-9]{12}$/', $database)) { throw new RuntimeException('An isolated test database is required'); }

class ResultsFixtureClient extends IntelligenceApiService
{
    public int $calls = 0;
    public bool $fail = false;
    public bool $healthFail = false;
    public stdClass $result;
    public function getRecommendations(?string $status = null): array|stdClass
    {
        $this->calls++;
        if ($this->fail) { throw new ApiException('Fixture unavailable', 502); }
        return $this->result;
    }
    public function health(): array|stdClass
    {
        if ($this->healthFail) { throw new ApiException('Fixture health unavailable', 502); }
        return (object)['status' => 'ok', 'agent_available' => false];
    }
}

$resultClient = new ResultsFixtureClient('http://fixture.invalid');
$validItem = (object)[
    'sku' => '0001_', 'forecast_date' => '2026-10-01T00:00:00', 'stock_date' => '2026-09-01T00:00:00',
    'model_name' => 'fixture', 'forecast_demand' => 7.5, 'current_stock' => 4, 'in_transit' => null,
    'available_stock' => null, 'net_requirement' => null, 'order_multiple' => 1, 'recommended_quantity' => null,
    'urgency' => 'review_required', 'explanation_components' => (object)['issues' => ['missing_or_invalid_in_transit']],
];
$catalogFixture = [(object)['sku' => '0001_', 'product_name' => 'Fixture connector', 'article' => 'IMT-1', 'unit' => null, 'has_forecast' => true], (object)['sku' => '0002_', 'product_name' => 'Unforecast product', 'article' => null, 'unit' => null, 'has_forecast' => false]];
$resultClient->result = (object)['total' => 1, 'items' => [$validItem], 'catalog' => $catalogFixture];
$resultService = new IntelligenceResultsService($resultClient);
$warehouseBefore = R::getAll('SELECT * FROM stock ORDER BY id');
$productsBefore = R::count('product');
$firstResult = $resultService->get();
check(!$firstResult['cached'] && !$firstResult['stale'] && $firstResult['read_only'] && !$firstResult['live_inventory_sync'], 'Python snapshot is fresh, read-only and separate from live inventory');
check(abs(time() - strtotime($firstResult['fetched_at'])) < 5, 'Cache dates use UTC independently of PHP/MySQL timezone');
check(count($firstResult['results']->catalog) === 2 && $firstResult['results']->catalog[1]->has_forecast === false && $firstResult['results']->catalog[0]->product_name === 'Fixture connector', 'Full catalog labels stay read-only and unforecast products are preserved');
check($firstResult['results']->items[0]->forecast_demand === 7.5 && $firstResult['results']->items[0]->in_transit === null && $firstResult['results']->items[0]->sku === '0001_', 'Snapshot preserves fractional quantities, unknown inputs and SKU');
check($resultService->get()['cached'] && $resultClient->calls === 1, 'Fresh cache avoids repeated Python requests');
$resultClient->fail = true;
$staleResult = $resultService->get(true);
check($staleResult['stale'] && $staleResult['error'] === 'Fixture unavailable' && $staleResult['results'] == $firstResult['results'], 'Unavailable Python keeps the last results with explicit stale/error state');
$resultService->get();
check($resultClient->calls === 2, 'Failed refresh is throttled for subsequent ordinary reads');
$resultClient->fail = false;
$resultClient->result = (object)['total' => 2, 'items' => [$validItem]];
check($resultService->get(true)['results'] == $firstResult['results'], 'Invalid upstream snapshot does not overwrite last good data');
$resultClient->result = (object)['total' => 1, 'items' => [$validItem]];
$resultClient->healthFail = true;
$healthFailedResult = $resultService->get(true);
check(!$healthFailedResult['stale'] && $healthFailedResult['health'] === null && $healthFailedResult['health_error'] !== null, 'Health outage does not hide valid deterministic recommendations');
$resultClient->healthFail = false;
check($resultService->get(true)['error'] === null, 'Successful refresh clears the outage');
R::exec('UPDATE intelligencecache SET fetched_at=?,checked_at=? WHERE id=?', ['2000-01-01 00:00:00', '2000-01-01 00:00:00', $resultClient->cacheKey()]);
$previousCalls = $resultClient->calls;
check(!$resultService->get()['cached'] && $resultClient->calls === $previousCalls + 1, 'Expired cache refreshes automatically');

$invalidLists = [[], (object)['total' => 2, 'items' => [$validItem]], (object)['total' => 2, 'items' => [$validItem, $validItem]]];
foreach (['invalid', [$catalogFixture[0], $catalogFixture[0]], [(object)['sku' => '0001_', 'product_name' => 123, 'has_forecast' => true]]] as $badCatalog) {
    $invalidLists[] = (object)['total' => 1, 'items' => [$validItem], 'catalog' => $badCatalog];
}
foreach ([['forecast_demand' => null], ['forecast_demand' => '7.5'], ['in_transit' => 'unknown'], ['sku' => 1], ['explanation_components' => (object)['issues' => 'not-an-array']], ['rolling_mean_3' => 'invalid'], ['annual_growth_factor' => 'invalid'], ['historical_lost_demand' => INF], ['demand_basis' => 123]] as $invalidFields) {
    $invalidLists[] = (object)['total' => 1, 'items' => [(object)array_replace((array)$validItem, $invalidFields)]];
}
foreach ($invalidLists as $invalidList) {
    try { $resultService->validate($invalidList); throw new RuntimeException('Invalid Python snapshot accepted'); }
    catch (ApiException $error) { check($error->status === 502, 'Malformed Python snapshot rejected'); }
}
$offlineClient = new ResultsFixtureClient('http://offline-fixture.invalid');
$offlineClient->fail = true;
try { (new IntelligenceResultsService($offlineClient))->get(); throw new RuntimeException('Invented snapshot returned'); }
catch (ApiException $error) { check($error->status === 502, 'First-load outage fails explicitly without demo fallback'); }
check(R::getAll('SELECT * FROM stock ORDER BY id') === $warehouseBefore && R::count('product') === $productsBefore && R::count('purchaseorder') === 0 && R::count('recommendation') === 0 && R::count('movement') === 0, 'Reading and refreshing Python never imports catalog, changes stock or creates orders');
