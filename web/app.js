import { intelligencePage, intelligenceDetail, catalogItems, filteredItems, procurementCsv } from './intelligence.js';
const $ = (selector, root = document) => root.querySelector(selector);
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const num = value => Number(value || 0).toLocaleString('ru-RU', { maximumFractionDigits: 1 });
const money = value => `${num(value)} ₸`;
const requestId = () => globalThis.crypto?.randomUUID?.() || `web-${Date.now()}-${Math.random().toString(36).slice(2, 14)}`;
const icon = name => `<i data-lucide="${name}" aria-hidden="true"></i>`;
const badge = (label, tone = '') => `<span class="badge ${tone}">${esc(label)}</span>`;
const action = (name, label, glyph, extra = '', className = '') => `<button data-action="${name}" class="${className}" ${extra}>${glyph ? icon(glyph) : ''}${esc(label)}</button>`;
const pages = {
  dashboard: ['Обзор', 'layout-dashboard', 'Общее состояние складских запасов'],
  inventory: ['Остатки', 'warehouse', 'Текущие запасы и ожидаемые поставки'],
  products: ['Товары', 'package', 'Каталог SKU и условия пополнения'],
  sales: ['Продажи', 'chart-no-axes-combined', 'История спроса и корректировки'],
  suppliers: ['Поставщики', 'truck', 'Контакты и условия закупки'],
  forecast: ['Прогноз', 'chart-column', 'Спрос на период поставки и пополнения'],
  procurement: ['Закупки', 'shopping-basket', 'Рекомендации, сгруппированные по поставщикам'],
  orders: ['Заказы поставщикам', 'clipboard-check', 'Подтверждённые закупки и приёмка'],
  agent: ['AI-агент', 'workflow', 'Выполнение расчёта и журнал запусков'],
};
const state = { token: sessionStorage.getItem('supplymind-token'), user: null, data: null, orders: [], page: 'dashboard', query: '', tab: 'draft', filter: 'all', selected: new Set(), edits: new Map(), busy: false, error: '', confirmation: null };
let toastTimer;
const intelligence = { snapshot: null, loading: false, error: '', filter: 'all', catalogSource: 'external', agent: { message: '', busy: false, error: '', result: null } };
const usesIntelligence = () => state.data?.ml_source === 'intelligence';
function icons() { window.lucide?.createIcons({ attrs: { 'stroke-width': 1.7 } }); }
function toast(message) { $('#toast').textContent = message; $('#toast').classList.add('visible'); clearTimeout(toastTimer); toastTimer = setTimeout(() => $('#toast').classList.remove('visible'), 6500); }
async function api(path, body) {
  const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 30000);
  try {
    const response = await fetch(`/api/v1/${path}`, { method: body === undefined ? 'GET' : 'POST', headers: { 'Content-Type': 'application/json', ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}) }, body: body === undefined ? undefined : JSON.stringify(body), signal: controller.signal });
    const payload = await response.json();
    if (response.status === 401 && path !== 'login') { state.token = null; state.user = null; sessionStorage.removeItem('supplymind-token'); login(); }
    if (!response.ok || !payload.success) throw new Error(payload.error || 'Ошибка сервера');
    return payload;
  } catch (error) { if (error.name === 'AbortError') throw new Error('Сервер не ответил вовремя. Повторите попытку.'); throw error; }
  finally { clearTimeout(timer); }
}
function login(message = '') {
  $('#app').innerHTML = `<main class="login"><form id="login-form"><div class="brand"><img src="/manager/qoyma.png" alt="Qoyma" width="220" height="74"></div><div><h1>Вход в рабочее пространство</h1><p class="muted">Менеджер закупок</p></div><label>Электронная почта<input name="email" type="email" value="manager@supplymind.local" autocomplete="username" required></label><label>Пароль<input name="password" type="password" autocomplete="current-password" required></label><p class="form-error" role="alert">${esc(message)}</p><button class="primary" type="submit">Войти ${icon('arrow-right')}</button></form></main>`;
  icons();
  $('#login-form').onsubmit = async event => {
    event.preventDefault(); const form = event.currentTarget; const button = $('button', form); button.disabled = true;
    try {
      const result = await api('login', Object.fromEntries(new FormData(form)));
      if (result.user.role !== 'manager') { state.token = result.token; await api('logout', {}); state.token = null; throw new Error('Для веб-панели необходима учётная запись менеджера'); }
      state.token = result.token; state.user = result.user; sessionStorage.setItem('supplymind-token', result.token); shell(); await refresh();
    } catch (error) { $('.form-error', form).textContent = error.message; button.disabled = false; }
  };
}
function shell() {
  $('#app').innerHTML = `<div class="shell"><aside class="sidebar"><a href="#dashboard" class="brand"><img src="/manager/qoyma.png" alt="Qoyma" width="196" height="65"></a><div><p class="nav-caption">РАБОЧЕЕ ПРОСТРАНСТВО</p><nav class="nav">${Object.entries(pages).map(([key, value]) => `<a href="#${key}" data-nav="${key}">${icon(value[1])}${value[0]}</a>`).join('')}</nav></div><div class="sidebar-footer"><span class="badge success">${icon('database')}Единая база склада</span><div class="user-row"><div class="avatar">М</div><div><strong>${esc(state.user.name)}</strong><br><small>Менеджер</small></div>${action('logout', '', 'log-out', 'title="Выйти" aria-label="Выйти"', 'icon-button ghost')}</div></div></aside><div class="workspace"><header class="topbar"><div class="row">${action('menu', '', 'menu', 'title="Меню" aria-label="Меню"', 'icon-button ghost mobile-menu')}<img class="mobile-brand" src="/manager/qoyma.png" alt="Qoyma" width="120" height="40"><span class="breadcrumb">Рабочее пространство <span aria-hidden="true"> / </span> <strong id="breadcrumb">Обзор</strong></span></div><div class="top-actions"><span id="source-pill"></span>${action('refresh', '', 'refresh-cw', 'title="Обновить данные" aria-label="Обновить данные"', 'icon-button ghost')}</div></header><main id="content" class="content"></main></div></div>`;
  render();
}
async function refresh() {
  try {
    const [data, orders] = await Promise.all([api('workspace'), api('purchase-orders')]);
    state.data = data; state.orders = orders.orders; state.error = '';
    const active = new Set(data.recommendations.filter(r => r.status === 'draft').map(r => String(r.id)));
    state.selected = new Set([...state.selected].filter(id => active.has(id)));
    if (usesIntelligence()) await refreshIntelligence(false);
  } catch (error) { state.error = error.message; }
  if (state.user && state.token) render();
}
const matches = (...values) => values.join(' ').toLocaleLowerCase('ru').includes(state.query.toLocaleLowerCase('ru'));
async function refreshIntelligence(force = true) {
  if (intelligence.loading) return;
  const session = state.token;
  intelligence.loading = true; intelligence.error = ''; render();
  try { const snapshot = (await api(`intelligence/results${force ? '?refresh=1' : ''}`)).data; if (state.token === session) intelligence.snapshot = snapshot; }
  catch (error) { if (state.token === session) intelligence.error = error.message; }
  finally { intelligence.loading = false; if (state.user && state.token) render(); }
}
function empty(title, text, glyph = 'inbox') { return `<div class="empty">${icon(glyph)}<h3>${esc(title)}</h3><p>${esc(text)}</p></div>`; }
function table(headers, rows) { return `<div class="table-wrap"><table><thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table>${rows.length ? '' : empty('Нет данных', 'Попробуйте изменить фильтр или добавить записи.')}</div>`; }
const row = cells => `<tr>${cells.map(c => `<td>${c}</td>`).join('')}</tr>`;
const productCell = (name, sku) => `<div class="product-name">${esc(name)}</div><small>${esc(sku)}</small>`;
function search(placeholder = 'Поиск по названию или артикулу') { return `<input type="search" id="search" placeholder="${esc(placeholder)}" aria-label="${esc(placeholder)}" value="${esc(state.query)}">`; }
function stat(label, value, detail, glyph, highlight = false) { return `<div class="stat ${highlight ? 'highlight' : ''}"><div class="stat-top">${esc(label)}${icon(glyph)}</div><strong>${esc(value)}</strong><small>${esc(detail)}</small></div>`; }
function heading() {
  const [title, , defaultSubtitle] = pages[state.page];
  const intelligenceSubtitles = { dashboard: 'Запасы, спрос и план пополнения', forecast: 'Ожидаемый спрос на месяц', procurement: 'Что закупить и почему', products: 'Каталог товаров и готовность расчётов', agent: 'Разбор закупок с AI-ассистентом' };
  const subtitle = usesIntelligence() ? intelligenceSubtitles[state.page] || defaultSubtitle : defaultSubtitle;
  let buttons = '';
  if (['dashboard', 'procurement', 'forecast', 'agent', ...(usesIntelligence() ? ['products'] : [])].includes(state.page)) buttons += usesIntelligence()
    ? action('intelligence-refresh', intelligence.loading ? 'Загрузка…' : 'Обновить результаты', 'refresh-cw', intelligence.loading ? 'disabled' : '', 'primary')
    : action('run', state.busy ? 'Расчёт выполняется…' : 'Рассчитать закупки', state.busy ? 'loader-circle' : 'sparkles', state.busy ? 'disabled' : '', 'primary');
  if (state.page === 'products' && (!usesIntelligence() || intelligence.catalogSource === 'warehouse')) buttons += action('add-product', 'Добавить товар', 'plus', '', 'primary');
  if (state.page === 'suppliers') buttons += action('add-supplier', 'Добавить поставщика', 'plus', '', 'primary');
  if (state.page === 'sales') buttons += action('add-sale', 'Данные за день', 'plus', '', 'primary');
  return `<div class="page-heading"><div><h1>${title}</h1><p>${subtitle}</p></div><div class="page-actions">${buttons}</div></div>`;
}
function chart(sales) {
  const grouped = new Map(); sales.forEach(s => grouped.set(s.date, (grouped.get(s.date) || 0) + Number(s.quantity_sold)));
  const days = [...grouped].sort((a, b) => a[0].localeCompare(b[0])); const max = Math.max(1, ...days.map(day => day[1]));
  if (!days.length) return empty('Нет истории продаж', 'Добавьте данные за день в разделе «Продажи».', 'chart-column');
  return `<div class="chart" role="img" aria-label="Продажи за 30 дней">${days.map(([date, qty]) => `<div class="chart-bar" tabindex="0" title="${esc(date)}: ${num(qty)} шт." style="height:${Math.max(1, qty / max * 100)}%"></div>`).join('')}</div><div class="chart-labels"><span>${esc(days[0][0])}</span><span>Продано, шт.</span><span>${esc(days.at(-1)[0])}</span></div>`;
}
function dashboard() {
  const d = state.data; const recommendations = d.recommendations.filter(r => r.status === 'draft');
  const risk = d.inventory.filter(s => Number(s.quantity_on_hand) < Number(s.reorder_point));
  const overstock = d.forecasts.filter(f => f.analysis.average_daily_demand > 0 && f.analysis.current_stock > f.analysis.average_daily_demand * 45);
  const total = d.inventory.reduce((sum, s) => sum + Number(s.unit_cost) * Number(s.quantity_on_hand), 0);
  return `<div class="stats">${stat('Стоимость запасов', money(total), `${d.warehouses.length} склада · ${d.products.length} SKU`, 'wallet', true)}${stat('Риск дефицита', num(risk.length), 'Ниже порога пополнения', 'triangle-alert')}${stat('Возможный избыток', num(overstock.length), 'Запас более чем на 45 дней', 'package-plus')}${stat('Рекомендации', num(recommendations.length), 'Ожидают решения менеджера', 'clipboard-list')}</div><div class="split"><section class="section"><div class="section-head"><h2>Динамика продаж</h2>${badge('Последние 30 дней', 'neutral')}</div><div>${chart(d.sales)}</div></section><section class="section"><div class="section-head"><h2>Приоритетные действия</h2><a href="#procurement" class="badge">К закупкам ${icon('arrow-up-right')}</a></div>${recommendations.length ? recommendations.slice(0, 3).map(r => `<div class="signal">${icon(r.analysis.urgency === 'critical' ? 'triangle-alert' : 'package')}<div><h3>${esc(r.product_name)}</h3><p>${esc(r.warehouse_name)} · ${num(r.recommended_quantity)} шт.</p></div>${action('analysis', '', 'arrow-up-right', `data-id="${r.id}" title="Разбор рекомендации" aria-label="Разбор рекомендации"`, 'icon-button ghost')}</div>`).join('') : empty('Нет новых рекомендаций', d.runs.length ? 'Все текущие решения обработаны.' : 'Запустите расчёт закупок.', 'circle-check')}</section></div><section class="section"><div class="section-head"><h2>Запасы по складам</h2><a href="#inventory" class="badge neutral">Все остатки ${icon('arrow-right')}</a></div>${table(['Склад', 'SKU', 'Остаток', 'В пути', 'Стоимость'], d.warehouses.map(w => { const items = d.inventory.filter(s => Number(s.warehouse_id) === Number(w.id)); return row([esc(w.name), num(items.length), `${num(items.reduce((s, i) => s + Number(i.quantity_on_hand), 0))} шт.`, `${num(items.reduce((s, i) => s + Number(i.in_transit), 0))} шт.`, money(items.reduce((s, i) => s + Number(i.unit_cost) * Number(i.quantity_on_hand), 0))]); }))}</section>`;
}
function inventory() {
  const filtered = state.data.inventory.filter(s => matches(s.name, s.sku, s.warehouse_name) && (state.filter === 'all' || (state.filter === 'low' ? Number(s.quantity_on_hand) < Number(s.reorder_point) : Number(s.in_transit) > 0)));
  return `<div class="toolbar">${search()}<select id="filter" aria-label="Статус запаса">${[['all', 'Все остатки'], ['low', 'Риск дефицита'], ['incoming', 'Ожидается поставка']].map(([k, v]) => `<option value="${k}" ${state.filter === k ? 'selected' : ''}>${v}</option>`).join('')}</select></div>${table(['Товар', 'Склад', 'На складе', 'В пути', 'Порог', 'Статус'], filtered.map(s => row([productCell(s.name, s.sku), esc(s.warehouse_name), num(s.quantity_on_hand), num(s.in_transit), num(s.reorder_point), badge(Number(s.quantity_on_hand) < Number(s.reorder_point) ? 'Ниже нормы' : 'В норме', Number(s.quantity_on_hand) < Number(s.reorder_point) ? 'warning' : 'success')])))}<section class="section"><h2>Последние движения</h2>${movementTable(state.data.movements.slice(0, 10))}</section>`;
}
const movementNames = { receive: 'Приёмка', issue: 'Выдача', transfer: 'Перемещение', writeoff: 'Списание', count: 'Инвентаризация' };
function movementTable(movements) { return table(['Операция', 'Товар', 'Склад', 'Количество', 'Было → Стало', 'Сотрудник'], movements.map(m => row([`${esc(movementNames[m.type] || m.type)}<small>${esc(m.created_at)}</small>`, productCell(m.product_name, m.sku), `${esc(m.warehouse_name)}${m.destination_name ? `<small>→ ${esc(m.destination_name)}</small>` : ''}`, num(m.quantity), `${num(m.before_qty)} → ${num(m.after_qty)}`, esc(m.user_name)]))); }
function products() { return `<div class="toolbar">${search()}<span class="muted">${state.data.products.length} SKU · ${state.data.categories.length} категорий</span></div>${table(['Товар', 'Категория', 'Штрихкод', 'Цена', 'Поставка'], state.data.products.filter(p => matches(p.name, p.sku, p.barcode)).map(p => row([productCell(p.name, p.sku), esc(p.category), esc(p.barcode), money(p.unit_cost), `${num(p.lead_time_days)} дн.`])))}`; }
function sales() { return `<div class="toolbar">${search()}<span class="muted">Последние 30 дней</span></div>${table(['Дата', 'Товар', 'Склад', 'Продано', 'Разовая продажа', 'Stockout'], state.data.sales.filter(s => matches(s.product_name, s.sku, s.warehouse_name)).slice(0, 200).map(s => row([esc(s.date), productCell(s.product_name, s.sku), esc(s.warehouse_name), num(s.quantity_sold), Number(s.one_time_quantity) ? badge(`${num(s.one_time_quantity)} шт. исключается`, 'warning') : '—', Number(s.stockout) ? badge('Дефицит', 'warning') : '—'])))}<p class="source-note">История спроса хранится отдельно от движений склада. Корректировка дня продаж не проводит складскую операцию.</p>`; }
function suppliers() { return `<div class="toolbar">${search('Название или контакт поставщика')}</div>${table(['Поставщик', 'Контакты', 'Товаров', 'Срок поставки', 'Рекомендации'], state.data.suppliers.filter(s => matches(s.name, s.contact_info)).map(s => row([`<strong>${esc(s.name)}</strong>`, esc(s.contact_info), num(state.data.supplier_products.filter(p => Number(p.supplier_id) === Number(s.id)).length), `${num(s.avg_lead_time_days)} дн.`, num(state.data.recommendations.filter(r => r.status === 'draft' && Number(r.supplier_id) === Number(s.id)).length)])))}`; }
function forecasts() { return `${state.data.forecasts.length ? `<div class="toolbar">${search()}${badge(state.data.ml_source === 'mock' ? 'Демонстрационный расчёт' : 'Python ML API', 'neutral')}</div>${table(['Товар / склад', 'Прогноз спроса', 'Период', 'Упущенный спрос', 'Разовые продажи', 'Закупка', ''], state.data.forecasts.filter(f => matches(f.product_name, f.analysis.sku)).map(f => row([productCell(f.product_name, f.warehouse_name), `${num(f.analysis.forecast_demand)} шт.`, `${num(f.analysis.horizon_days)} дн.`, `+${num(f.analysis.lost_demand)} шт.`, `−${num(f.analysis.excluded_one_time_order)} шт.`, `${num(f.analysis.recommended_quantity)} шт.`, action('forecast-analysis', 'Разбор', 'arrow-up-right', `data-id="${f.id}"`, 'table-button')])))} ` : empty('Прогноз ещё не рассчитан', 'Запустите расчёт закупок, чтобы получить прогноз для каждой позиции.', 'chart-column')}`; }
function quantity(r) { return state.edits.has(String(r.id)) ? state.edits.get(String(r.id)) : Number(r.recommended_quantity); }
function procurement() {
  const all = state.data.recommendations.filter(r => r.status === state.tab && matches(r.product_name, r.analysis.sku, r.analysis.supplier.name));
  const groups = new Map(); all.forEach(r => { const k = r.supplier_id; if (!groups.has(k)) groups.set(k, []); groups.get(k).push(r); });
  return `<div class="tabs"><button data-action="tab" data-tab="draft" class="${state.tab === 'draft' ? 'active' : ''}">К рассмотрению · ${state.data.recommendations.filter(r => r.status === 'draft').length}</button><button data-action="tab" data-tab="approved" class="${state.tab === 'approved' ? 'active' : ''}">Подтверждены</button></div><div class="toolbar">${search('Товар, артикул или поставщик')}${state.tab === 'draft' ? action('review-selected', `Подтвердить выбранные (${state.selected.size})`, 'check', state.selected.size ? '' : 'disabled', 'primary') : ''}</div>${[...groups].map(([id, items]) => `<section class="supplier-group"><div class="supplier-title"><div class="row">${icon('truck')}<div><h2>${esc(items[0].analysis.supplier.name)}</h2><small>${items.length} позиций · ${money(items.reduce((sum, r) => sum + Number(r.analysis.supplier.unit_price) * (state.tab === 'draft' ? quantity(r) : Number(r.manager_quantity)), 0))}</small></div></div><div class="supplier-meta">${badge(items.some(r => r.analysis.urgency === 'critical') ? 'Есть срочные позиции' : 'Плановое пополнение', items.some(r => r.analysis.urgency === 'critical') ? 'warning' : 'neutral')}${state.tab === 'draft' ? action('review-group', 'Проверить заказ', 'arrow-right', `data-supplier="${id}"`, 'ghost') : badge('Подтверждено', 'success')}</div></div>${table([state.tab === 'draft' ? '' : '№', 'Товар / склад', 'Остаток', 'В пути', 'Прогноз', 'Количество', 'Срочность', ''], items.map(r => row([state.tab === 'draft' ? `<input type="checkbox" data-select="${r.id}" ${state.selected.has(String(r.id)) ? 'checked' : ''} aria-label="Выбрать ${esc(r.product_name)}">` : esc(r.id), productCell(r.product_name, r.warehouse_name), num(r.analysis.current_stock), num(r.analysis.in_transit), num(r.analysis.forecast_demand), state.tab === 'draft' ? `<input type="number" min="${r.analysis.supplier.min_order_qty}" max="10000000" step="1" data-quantity="${r.id}" value="${quantity(r)}" aria-label="Количество ${esc(r.product_name)}"><small>Рекомендация: ${num(r.recommended_quantity)}</small>` : num(r.manager_quantity), badge(r.analysis.urgency === 'critical' ? 'Срочно' : 'Планово', r.analysis.urgency === 'critical' ? 'warning' : 'neutral'), action('analysis', '', 'arrow-up-right', `data-id="${r.id}" title="Почему этот заказ" aria-label="Почему этот заказ"`, 'icon-button ghost')])))} </section>`).join('')}${groups.size ? '' : empty('Рекомендаций нет', state.query ? 'Измените поисковый запрос.' : 'Запустите расчёт или откройте подтверждённые рекомендации.', 'circle-check')}`;
}
function orders() { const items = state.orders.filter(o => matches(o.product_name, o.supplier_name, o.sku)); return `<div class="toolbar">${search('Товар или поставщик')}<span class="muted">${items.length} позиций</span></div>${table(['Заказ', 'Товар', 'Поставщик / склад', 'Заказано', 'Принято', 'Сумма', 'Ожидаем', 'Статус'], items.map(o => row([`№${esc(o.id)}<small>${esc(o.batch_id || 'Ранее созданный')}</small>`, productCell(o.product_name, o.sku), `${esc(o.supplier_name)}<small>${esc(o.warehouse_name)}</small>`, num(o.suggested_qty), num(o.received_qty), money(Number(o.unit_price) * Number(o.suggested_qty)), esc(o.expected_at || '—'), badge(o.status === 'received' ? 'Получен' : 'Ожидается поставка', o.status === 'received' ? 'success' : 'neutral')])))}<p class="source-note">Приёмка выполняется сотрудником в мобильном приложении. Подтверждение заказа само по себе не увеличивает остаток.</p>`; }
function agent() {
  const run = state.data.runs[0];
  return `<div class="connection"><strong>Mobile / Web</strong>${icon('arrow-right')}<strong>PHP REST API</strong>${icon('arrow-right')}<strong>MySQL</strong>${icon('arrow-left-right')}<strong>${state.data.ml_source === 'mock' ? 'Mock ML' : 'Python ML API'}</strong></div>${state.busy ? `<div class="loading">${icon('loader-circle')}Выполняется расчёт. Ожидаем ответ сервера…</div>` : run ? `<section class="section"><div class="section-head"><h2>Запуск №${run.id}</h2>${badge(run.status === 'completed' ? 'Завершён' : run.status === 'failed' ? 'Ошибка' : 'Выполняется', run.status === 'completed' ? 'success' : 'warning')}</div><ol class="steps">${run.steps.map(step => `<li class="step"><div class="step-icon">${icon(step.status === 'failed' ? 'triangle-alert' : 'check')}</div><div><h3>${esc(step.name)}</h3><p>${esc(step.detail)}</p></div><small>${esc(new Date(step.at).toLocaleTimeString('ru-RU'))}</small></li>`).join('')}</ol></section>` : empty('Первый запуск впереди', 'Данные склада готовы к расчёту.', 'workflow')}<section class="section"><h2>История запусков</h2>${state.data.runs.map(r => `<div class="run-row"><span>№${r.id} · ${esc(r.created_at)}</span><div class="row">${badge(r.source === 'mock' ? 'Mock' : 'ML API', 'neutral')}${badge(r.status === 'completed' ? 'Завершён' : r.status === 'failed' ? 'Ошибка' : 'Выполняется', r.status === 'completed' ? 'success' : 'warning')}</div></div>`).join('') || '<p class="muted">Пока нет запусков</p>'}</section>`;
}
function render() {
  if (!$('#content')) return;
  document.querySelectorAll('[data-nav]').forEach(link => link.classList.toggle('active', link.dataset.nav === state.page));
  $('#breadcrumb').textContent = pages[state.page][0];
  $('#source-pill').innerHTML = badge(usesIntelligence() ? 'Systeme Electric' : state.data?.ml_source === 'fastapi' ? 'Прогноз спроса' : 'Демонстрационные данные', 'neutral');
  const views = { dashboard, inventory, products, sales, suppliers, forecast: forecasts, procurement, orders, agent };
  if (usesIntelligence()) for (const page of ['dashboard', 'forecast', 'procurement', 'agent']) views[page] = () => intelligencePage(page, intelligence.snapshot, { ...intelligence, query: state.query });
  if (usesIntelligence()) views.products = () => `<div class="tabs catalog-tabs" role="tablist" aria-label="Источник каталога">${[['external', 'Systeme Electric'], ['warehouse', 'Рабочий склад']].map(([key, label]) => `<button role="tab" aria-selected="${intelligence.catalogSource === key}" class="${intelligence.catalogSource === key ? 'active' : ''}" data-action="catalog-source" data-source="${key}">${label}</button>`).join('')}</div>` + (intelligence.catalogSource === 'external' ? intelligencePage('products', intelligence.snapshot, { ...intelligence, query: state.query }) : products());
  $('#content').innerHTML = heading() + (state.error ? `<div class="error-banner" role="alert">${icon('triangle-alert')}<span>${esc(state.error)}</span>${action('refresh', 'Повторить', 'refresh-cw', '', 'ghost')}</div>` : '') + (state.data ? views[state.page]() : state.error ? '' : `<div class="loading">${icon('loader-circle')}Загрузка данных…</div>`);
  icons();
}
function dialog(title, subtitle, content, footer = '') {
  const node = $('#detail'); node.innerHTML = `<div class="dialog-head"><div><h2>${esc(title)}</h2><p class="muted">${esc(subtitle)}</p></div>${action('close', '', 'x', 'aria-label="Закрыть" title="Закрыть"', 'icon-button ghost')}</div><div class="dialog-body">${content}</div>${footer ? `<div class="dialog-foot">${footer}</div>` : ''}`;
  if (!node.open) node.showModal(); icons();
}
function showAnalysis(record) {
  const a = record.analysis;
  const facts = [['На складе', `${num(a.current_stock)} шт.`], ['Товары в пути', `${num(a.in_transit)} шт.`], ['Прогноз спроса', `${num(a.forecast_demand)} шт.`], ['Упущенный спрос', `+${num(a.lost_demand)} шт.`], ['Разовые продажи', `−${num(a.excluded_one_time_order)} шт.`], ['Дней stockout', num(a.stockout_days)], ['Рост спроса', `${num(a.demand_growth_percent)}%`], ['Сезонность', `×${num(a.seasonality_index)}`], ['Срок поставки', `${num(a.lead_time_days)} дн.`]];
  const forecastId = record.forecast_id || record.id;
  dialog(record.product_name, `${a.sku} · ${record.warehouse_name}`, `<div class="row">${badge(record.source === 'fastapi' ? 'Python ML API' : 'Mock ML · демонстрация', 'neutral')}${badge(a.urgency === 'critical' ? 'Риск дефицита' : 'Плановый расчёт', a.urgency === 'critical' ? 'warning' : 'neutral')}</div><div class="facts">${facts.map(([label, value]) => `<div class="fact"><span>${label}</span><strong>${value}</strong></div>`).join('')}</div><div class="section"><div class="section-head"><h2>Продажи за 30 дней</h2><span class="muted">${num(a.horizon_days)} дн. горизонт прогноза</span></div><div>${chart(state.data.sales.filter(s => Number(s.product_id) === Number(record.product_id) && Number(s.warehouse_id) === Number(record.warehouse_id)))}</div></div><div class="explanation"><h3>Почему рекомендовано ${num(a.recommended_quantity)} шт.</h3><p>${esc(a.explanation)}</p><small>${esc(a.seasonality_note || '')}</small></div><section class="section" data-ai-forecast="${forecastId}"><div class="section-head"><h3>Разбор OpenAI</h3>${action('openai-analysis', 'Запросить объяснение', 'sparkles', `data-forecast="${forecastId}"`, 'ghost')}</div><div class="ai-result" role="status">${record.ai_source === 'openai' && record.ai_text ? `<p>${esc(record.ai_text)}</p><small>OpenAI · ${esc(record.ai_model)} · сохранённое объяснение</small>` : ''}</div></section><div class="section-head"><div><h3>${esc(a.supplier.name)}</h3><p class="muted">Минимальная партия ${num(a.supplier.min_order_qty)} шт. · ${money(a.supplier.unit_price)} за шт.</p></div></div>`, action('close', 'Закрыть', '', '', 'ghost'));
}
function review(items) {
  if (!items.length) return;
  for (const item of items) { const qty = quantity(item); if (!Number.isInteger(qty) || qty < item.analysis.supplier.min_order_qty || qty > 10000000) { toast(`Проверьте количество: ${item.product_name}`); return; } }
  state.confirmation = { request_id: requestId(), items: items.map(r => ({ id: Number(r.id), quantity: quantity(r), version: Number(r.version) })) };
  dialog('Подтверждение закупки', 'Решение менеджера', `${table(['Товар', 'Поставщик', 'Количество', 'Сумма'], items.map(r => row([esc(r.product_name), esc(r.analysis.supplier.name), `${num(quantity(r))} шт.`, money(quantity(r) * Number(r.analysis.supplier.unit_price))])))}<div class="section-head"><h3>Общая сумма</h3><h2>${money(items.reduce((sum, r) => sum + quantity(r) * Number(r.analysis.supplier.unit_price), 0))}</h2></div><p class="muted">После подтверждения заказ появится в ожидаемых поставках. Отправка поставщику автоматически не выполняется.</p><p class="form-error" id="approval-error" role="alert"></p>`, action('close', 'Отмена', '', '', 'ghost') + action('confirm', 'Подтвердить заказ', 'check', '', 'primary'));
}
function field(label, name, type = 'text', value = '', extra = '') { return `<label>${esc(label)}<input name="${name}" type="${type}" value="${esc(value)}" required ${extra}></label>`; }
function select(label, name, items) { return `<label>${label}<select name="${name}" required>${items.map(item => `<option value="${item.id}">${esc(item.name)}</option>`).join('')}</select></label>`; }
function formDialog(kind) {
  const d = state.data;
  const config = {
    product: ['Новый товар', 'products', field('Название', 'name') + field('Артикул', 'sku') + field('Штрихкод / QR', 'barcode') + field('Категория', 'category') + field('Цена поставщика, ₸', 'unit_cost', 'number', '', 'min="0" step="0.01"') + field('Срок поставки, дн.', 'lead_time_days', 'number', '3', 'min="1"') + select('Поставщик', 'supplier_id', d.suppliers) + field('Минимальная партия', 'min_order_qty', 'number', '1', 'min="1"')],
    supplier: ['Новый поставщик', 'suppliers', field('Название', 'name') + field('Контакты', 'contact_info') + field('Срок поставки, дн.', 'avg_lead_time_days', 'number', '3', 'min="1"')],
    sale: ['Продажи за день', 'sales', select('Товар', 'product_id', d.products) + select('Склад', 'warehouse_id', d.warehouses) + field('Дата', 'date', 'date', new Date().toLocaleDateString('sv-SE')) + field('Всего продано', 'quantity', 'number', '', 'min="0"') + field('Из них разовая крупная продажа', 'one_time_quantity', 'number', '0', 'min="0"') + '<label class="check-label"><input name="stockout" type="checkbox">Был дефицит товара</label><p class="muted span-all">Запись заменит итог продаж выбранного товара и склада за этот день. Складской остаток не изменится.</p>'],
  }[kind];
  dialog(config[0], '', `<form id="editor-form" data-endpoint="${config[1]}"><div class="form-grid">${config[2]}</div><p class="form-error" role="alert"></p></form>`, action('close', 'Отмена', '', '', 'ghost') + '<button type="submit" form="editor-form" class="primary">Сохранить</button>');
}
document.addEventListener('click', async event => {
  const button = event.target.closest('[data-action]'); if (!button || button.disabled) return;
  const name = button.dataset.action;
  try {
    if (name === 'intelligence-refresh') await refreshIntelligence();
    if (name === 'intelligence-filter') { intelligence.filter = button.dataset.filter; render(); }
    if (name === 'intelligence-export' && intelligence.snapshot) {
      const items = filteredItems(intelligence.snapshot, state.query, intelligence.filter);
      const url = URL.createObjectURL(new Blob([procurementCsv(items, intelligence.snapshot)], { type: 'text/csv;charset=utf-8' }));
      const link = document.createElement('a'); link.href = url; link.download = 'qoyma-procurement.csv';
      document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    if (name === 'catalog-source') { intelligence.catalogSource = button.dataset.source; state.query = ''; render(); }
    if (name === 'intelligence-detail') {
      const item = catalogItems(intelligence.snapshot).find(item => item.sku === button.dataset.sku);
      if (item) dialog(item.product_name || item.sku, 'Разбор закупки · данные из файлов', intelligenceDetail(item), action('close', 'Закрыть', '', '', 'ghost'));
    }
    if (name === 'menu') $('.sidebar').classList.toggle('open');
    if (name === 'close') $('#detail').close();
    if (name === 'refresh') { button.disabled = true; try { await refresh(); } finally { button.disabled = false; } }
    if (name === 'logout') { await api('logout', {}); sessionStorage.removeItem('supplymind-token'); state.token = null; state.user = null; state.data = null; intelligence.snapshot = null; intelligence.agent = { message: '', busy: false, error: '', result: null }; login(); }
    if (name === 'tab') { state.tab = button.dataset.tab; render(); }
    if (name === 'analysis') showAnalysis(state.data.recommendations.find(r => String(r.id) === button.dataset.id));
    if (name === 'forecast-analysis') showAnalysis(state.data.forecasts.find(r => String(r.id) === button.dataset.id));
    if (name === 'openai-analysis') {
      button.disabled = true;
      const panel = button.closest('[data-ai-forecast]'); const resultNode = $('.ai-result', panel);
      resultNode.textContent = 'Ожидаем объяснение OpenAI…';
      try {
        const { analysis } = await api('forecast/analyze', { forecast_id: Number(button.dataset.forecast) });
        resultNode.innerHTML = analysis.source === 'openai'
          ? `<p>${esc(analysis.text)}</p><small>OpenAI · ${esc(analysis.model)}${analysis.cached ? ' · сохранённое объяснение' : ''}</small>`
          : `<p class="form-error">${esc(analysis.message)}</p><p class="muted">Выше показано расчётное объяснение. Ответ OpenAI не получен.</p>`;
        if (analysis.source === 'openai') {
          for (const record of [...state.data.recommendations, ...state.data.forecasts]) {
            if (Number(record.forecast_id || record.id) === Number(button.dataset.forecast)) Object.assign(record, { ai_source: 'openai', ai_text: analysis.text, ai_model: analysis.model });
          }
        }
      } catch (error) { resultNode.textContent = error.message; }
      finally { button.disabled = false; }
    }
    if (name === 'review-selected') review(state.data.recommendations.filter(r => r.status === 'draft' && state.selected.has(String(r.id))));
    if (name === 'review-group') review(state.data.recommendations.filter(r => r.status === 'draft' && String(r.supplier_id) === button.dataset.supplier && matches(r.product_name, r.analysis.sku, r.analysis.supplier.name)));
    if (name === 'confirm') {
      button.disabled = true;
      try { const result = await api('procurement/approve', state.confirmation); $('#detail').close(); state.selected.clear(); toast(result.message); await refresh(); }
      catch (error) { $('#approval-error').textContent = error.message; button.disabled = false; }
    }
    if (name === 'run' && !state.busy) {
      state.busy = true; state.error = ''; render();
      try { const result = await api('procurement/run', {}); toast(`Расчёт №${result.run_id} завершён. Рекомендации готовы к проверке.`); }
      catch (error) { toast(error.message); }
      finally { state.busy = false; await refresh(); }
    }
    if (name === 'add-product') formDialog('product');
    if (name === 'add-supplier') formDialog('supplier');
    if (name === 'add-sale') formDialog('sale');
  } catch (error) { toast(error.message); }
});
document.addEventListener('input', event => {
  if (event.target.name === 'message' && event.target.closest('#intelligence-agent-form')) intelligence.agent.message = event.target.value;
  if (event.target.id === 'search') { const caret = event.target.selectionStart; state.query = event.target.value; render(); $('#search')?.focus(); $('#search')?.setSelectionRange(caret, caret); }
  if (event.target.dataset.quantity) state.edits.set(event.target.dataset.quantity, Number(event.target.value));
});
document.addEventListener('change', event => {
  const element = event.target;
  if (element.id === 'filter') { state.filter = element.value; render(); }
  if (element.dataset.select) { element.checked ? state.selected.add(element.dataset.select) : state.selected.delete(element.dataset.select); render(); }
  if (element.dataset.quantity) {
    const recommendation = state.data.recommendations.find(r => String(r.id) === element.dataset.quantity);
    const group = state.data.recommendations.filter(r => r.status === 'draft' && r.supplier_id === recommendation.supplier_id && matches(r.product_name, r.analysis.sku, r.analysis.supplier.name));
    const total = element.closest('.supplier-group')?.querySelector('.supplier-title small');
    if (total) total.textContent = `${group.length} позиций · ${money(group.reduce((sum, r) => sum + Number(r.analysis.supplier.unit_price) * quantity(r), 0))}`;
  }
});
document.addEventListener('submit', async event => {
  if (event.target.id === 'intelligence-agent-form') {
    event.preventDefault(); if (intelligence.agent.busy) return;
    const session = state.token;
    intelligence.agent.busy = true; intelligence.agent.error = ''; intelligence.agent.result = null; render();
    try { const result = (await api('intelligence/agent/run', { message: intelligence.agent.message })).data; if (state.token === session) intelligence.agent.result = result; }
    catch (error) { if (state.token === session) intelligence.agent.error = error.message; }
    finally { intelligence.agent.busy = false; if (state.user && state.token) render(); }
    return;
  }
  if (event.target.id !== 'editor-form') return;
  event.preventDefault(); const form = event.target; const button = $('button[type=submit]', $('#detail')); button.disabled = true;
  const body = Object.fromEntries(new FormData(form)); if (body.stockout) body.stockout = true;
  try { await api(form.dataset.endpoint, body); $('#detail').close(); toast('Данные сохранены'); await refresh(); }
  catch (error) { $('.form-error', form).textContent = error.message; button.disabled = false; }
});
function navigate() { const hash = location.hash.slice(1); state.page = pages[hash] ? hash : 'dashboard'; state.query = ''; state.filter = 'all'; $('.sidebar')?.classList.remove('open'); render(); }
window.addEventListener('hashchange', navigate);
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && state.user && !$('#detail').open && !state.busy) void refresh(); });
navigate();
if (state.token) {
  try { const session = await api('session'); state.user = session.user; if (state.user.role !== 'manager') throw new Error('Нужна учётная запись менеджера'); shell(); await refresh(); }
  catch (error) { state.token = null; sessionStorage.removeItem('supplymind-token'); login(error.message); }
} else login();
