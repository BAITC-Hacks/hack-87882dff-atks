const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const number = value => value == null ? 'Нет данных' : Number(value).toLocaleString('ru-RU', { maximumFractionDigits: 2 });
const date = value => value ? new Date(value).toLocaleDateString('ru-RU') : 'не указана';
const month = value => value ? new Date(value).toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }) : 'период не указан';
const icon = name => `<i data-lucide="${name}" aria-hidden="true"></i>`;
const badge = (text, tone = 'neutral') => `<span class="badge ${tone}">${esc(text)}</span>`;
const status = item => item.has_forecast === false ? ['Без прогноза', 'neutral'] : item.urgency === 'review_required' || item.recommended_quantity === null ? ['Уточнить данные', 'warning'] : item.recommended_quantity > 0 ? ['Нужно пополнение', 'accent'] : ['Запаса достаточно', 'success'];
const issues = { missing_or_invalid_in_transit: 'товары в пути', missing_or_invalid_stock: 'остаток', missing_or_invalid_current_stock: 'остаток', missing_or_invalid_order_multiple: 'шаг заказа', stale_stock_snapshot: 'актуальная дата остатка' };
const metric = (label, value, note, glyph) => `<div class="stat"><span class="stat-top">${esc(label)}${icon(glyph)}</span><strong>${esc(number(value))}</strong><small>${esc(note)}</small></div>`;
const action = item => `<button class="icon-button ghost" data-action="intelligence-detail" data-sku="${esc(item.sku)}" title="Открыть разбор" aria-label="Разбор ${esc(item.product_name || item.sku)}">${icon('arrow-up-right')}</button>`;

export function catalogItems(snapshot) {
  if (!snapshot) return [];
  const forecasts = new Map(snapshot.results.items.map(item => [item.sku, item]));
  const catalog = new Map((snapshot.results.catalog || []).map(item => [item.sku, item]));
  return [...new Set([...catalog.keys(), ...forecasts.keys()])].map(sku => ({
    forecast_demand: null, recommended_quantity: null, explanation_components: {},
    ...forecasts.get(sku), ...catalog.get(sku), sku, has_forecast: forecasts.has(sku),
  })).sort((a, b) => (a.product_name || a.sku).localeCompare(b.product_name || b.sku, 'ru'));
}

export function recommendationText(item) {
  if (item.has_forecast === false) return 'Товар есть в исходных файлах, но прогноз для него не рассчитан. Рекомендованное количество пока неизвестно.';
  if (item.recommended_quantity === null || item.urgency === 'review_required') return `Нужно уточнить: ${(item.explanation_components.issues || []).map(value => issues[value] || 'исходные данные').join(', ') || 'исходные данные'}. До проверки количество к закупке не определено.`;
  if (item.recommended_quantity === 0) return 'Имеющегося остатка и ожидаемых поставок достаточно для прогнозируемого спроса на месяц. Пополнение не требуется.';
  const unit = (item.unit || 'ед.').replace(/\.$/, '');
  return `Для покрытия спроса не хватает ${number(item.net_requirement)} ${unit}. Заказ должен быть кратен ${number(item.order_multiple)} ${unit}. Поэтому рекомендовано ${number(item.recommended_quantity)} ${unit}.`;
}

export function calculationMethod(item) {
  const names = { seasonal_trend: 'Сезонность и годовой рост', previous_year: 'Спрос того же месяца прошлого года',
    previous_month: 'Последний полный месяц', rolling_3: 'Средний спрос за 3 месяца',
    rolling_3_fallback: 'Средний спрос за 3 месяца', historical_mean_fallback: 'Средний спрос по истории', catboost: 'Модель спроса по истории и календарю' };
  return names[item.effective_model_name || item.model_name] || 'Метод не указан';
}

export function procurementCsv(items, snapshot) {
  const cell = value => {
    const text = String(value ?? '');
    const safe = /^\s*[=+@\-\t\r\n]/.test(text) ? `'${text}` : text;
    return `"${safe.replace(/"/g, '""')}"`;
  };
  const headers = ['Код', 'Товар', 'Артикул', 'Ед.', 'Поставщик', 'Срок поставки', 'Дата остатка',
    'Остаток по файлу', 'В пути', 'Месяц прогноза', 'Спрос', 'К закупке', 'Статус', 'Метод',
    'Потерянный спрос за историю', 'Объяснение', 'Статус данных', 'Обновлено'];
  const rows = items.map(item => [item.sku, item.product_name, item.article, item.unit,
    item.supplier_name || 'Не указан', item.lead_time_days ?? 'Не указан', item.stock_date,
    item.current_stock, item.in_transit, item.forecast_date, item.forecast_demand, item.recommended_quantity,
    status(item)[0], calculationMethod(item), item.historical_lost_demand, recommendationText(item),
    snapshot?.stale ? 'Сохранённая копия; требуется обновление' : 'Исторические файлы; не текущие остатки', snapshot?.fetched_at]);
  return '\ufeff' + [headers, ...rows].map(row => row.map(cell).join(';')).join('\r\n');
}

export function filteredItems(snapshot, query = '', filter = 'all') {
  return catalogItems(snapshot).filter(item => [item.sku, item.product_name, item.article].join(' ').toLowerCase().includes(query.trim().toLowerCase()))
    .filter(item => filter === 'all' || (filter === 'missing' ? !item.has_forecast : filter === 'review_required' ? item.has_forecast && status(item)[0] === 'Уточнить данные' : item.recommended_quantity > 0));
}

function supplierGroups(items) {
  const groups = new Map();
  items.forEach(item => { const name = item.supplier_name || 'Поставщик не указан'; if (!groups.has(name)) groups.set(name, []); groups.get(name).push(item); });
  return [...groups].map(([name, rows]) => `<section class="section"><div class="section-head"><h2>${esc(name)}</h2>${badge(`${rows.length} позиций`, 'neutral')}</div>${!rows[0].supplier_name ? '<p class="source-note">Для подтверждения заказа нужны поставщик, срок поставки и проверка актуального остатка.</p>' : ''}${table(rows)}</section>`).join('') || table([]);
}

function analysisRows(item) {
  const rows = [['Метод прогноза', calculationMethod(item)], ['Средний спрос за 3 месяца', number(item.rolling_mean_3)],
    ['Потерянный спрос за историю', number(item.historical_lost_demand)], ['Исключённые месячные всплески', number(item.bulk_quantity_excluded)]];
  if (item.effective_model_name === 'seasonal_trend') rows.push(['Спрос в том же месяце год назад', number(item.seasonal_reference_demand)], ['Коэффициент годового роста', number(item.annual_growth_factor)]);
  return `<dl class="analysis-rows">${rows.map(([label, value]) => `<div><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`).join('')}</dl><p class="source-note">Потерянный спрос уже учтён в истории для нового расчёта, повторно к заказу не прибавляется. Месячные всплески не подтверждают разовую покупку конкретного клиента.</p>`;
}

function table(items) {
  if (!items.length) return '<div class="empty"><h3>Нет подходящих позиций</h3><p>Измените поиск или фильтр.</p></div>';
  return `<div class="table-wrap"><table class="forecast-table"><thead><tr><th>Товар / артикул</th><th>Остаток по файлу</th><th>В пути</th><th>Спрос на месяц</th><th>К закупке</th><th>Статус</th><th></th></tr></thead><tbody>${items.map(item => `<tr><td><div class="product-name">${esc(item.product_name || 'Товар без наименования')}</div><small>${item.article ? `Арт. ${esc(item.article)} · ` : ''}Код ${esc(item.sku)}</small></td><td>${esc(number(item.current_stock))}${item.stock_date ? `<small>на ${esc(date(item.stock_date))}</small>` : ''}</td><td>${esc(number(item.in_transit))}</td><td>${esc(number(item.forecast_demand))}${item.forecast_date ? `<small>${esc(month(item.forecast_date))}</small>` : ''}</td><td><strong class="${item.recommended_quantity > 0 ? 'quantity-accent' : ''}">${esc(number(item.recommended_quantity))}</strong>${item.unit ? `<small>${esc(item.unit)}</small>` : ''}</td><td>${badge(...status(item))}</td><td>${action(item)}</td></tr>`).join('')}</tbody></table></div>`;
}

export function intelligenceDetail(item) {
  const facts = [[item.stock_date ? `Остаток на ${date(item.stock_date)}` : 'Остаток по файлу', item.current_stock], ['Товары в пути', item.in_transit], [`Спрос: ${month(item.forecast_date)}`, item.forecast_demand], ['Не хватает для спроса', item.net_requirement], ['Шаг заказа', item.order_multiple], ['К закупке', item.recommended_quantity]];
  return `<div class="intel-provenance">${badge(...status(item))}<span>${item.article ? `Арт. ${esc(item.article)} · ` : ''}Код ${esc(item.sku)}${item.unit ? ` · ${esc(item.unit)}` : ''}</span></div>${item.has_forecast === false ? '' : `<div class="facts">${facts.map(([label, value]) => `<div class="fact"><span>${esc(label)}</span><strong>${esc(number(value))}</strong></div>`).join('')}</div>`}<section class="section"><h3>${item.has_forecast === false ? 'Нет готового расчёта' : 'Почему такая рекомендация'}</h3><p class="recommendation-copy">${esc(recommendationText(item))}</p>${item.has_forecast !== false ? analysisRows(item) : ''}<p class="source-note">Остатки взяты из загруженных файлов, а не из текущих складских операций. Поставщик и срок поставки не указаны: перед заказом их нужно проверить.</p></section>`;
}

export function intelligencePage(page, snapshot, options) {
  const { query = '', filter = 'all', loading, error, agent } = options;
  if (!snapshot) return `<div class="${loading ? 'loading' : 'empty'}">${icon(loading ? 'loader-circle' : 'triangle-alert')}<p>${esc(error || (loading ? 'Получаем каталог и прогнозы…' : 'Данные пока не загружены.'))}</p></div>`;
  const all = catalogItems(snapshot);
  const replenishment = all.filter(item => item.recommended_quantity > 0);
  const review = all.filter(item => item.has_forecast && (item.urgency === 'review_required' || item.recommended_quantity === null));
  const missing = all.filter(item => !item.has_forecast);
  const stale = snapshot.stale || Boolean(error);
  const warning = error || snapshot.error || (page === 'agent' ? snapshot.health_error : null);
  const header = `<div class="source-bar"><div>${icon('file-spreadsheet')}<strong>Systeme Electric</strong>${badge('Только просмотр')}${stale ? badge('Сохранённая копия', 'warning') : ''}</div><span>Обновлено ${esc(new Date(snapshot.fetched_at).toLocaleString('ru-RU'))}</span></div>${warning ? `<div class="error-banner" role="alert">${icon('triangle-alert')}${esc(warning)}</div>` : ''}<p class="source-note">Данные из загруженных файлов. Новые складские операции пока не учтены. Заказы не отправляются.</p>`;
  const stats = `<div class="stats">${metric('Товаров в каталоге', all.length, `${snapshot.results.total} с готовым прогнозом`, 'package')}${metric('Нужно пополнение', replenishment.length, 'Позиции с нехваткой запаса', 'shopping-basket')}${metric('Уточнить данные', review.length, 'Не хватает данных для заказа', 'circle-alert')}${metric('Без прогноза', missing.length, 'Расчёт пока отсутствует', 'chart-no-axes-column')}</div>`;
  if (page === 'dashboard') return `${header}${stats}<section class="section"><div class="section-head"><div><h2>Что нужно закупить</h2><p class="muted">С учётом остатка, ожидаемых поставок и шага заказа</p></div><a class="text-link" href="#procurement">План закупок ${icon('arrow-up-right')}</a></div>${table(replenishment.slice(0, 8))}</section><section class="section"><h2>Нужна проверка</h2>${review.slice(0, 4).map(item => `<div class="signal">${icon('circle-alert')}<div><h3>${esc(item.product_name || item.sku)}</h3><p>${esc(recommendationText(item))}</p></div>${action(item)}</div>`).join('') || '<p class="muted">Все данные заполнены.</p>'}</section>`;
  if (page === 'agent') {
    const available = snapshot.health?.agent_available === true && !stale && !snapshot.error;
    const local = snapshot.health?.metadata?.agent_mode === 'local';
    const toolLabels = { get_recommendation: 'Рекомендация проверена', get_forecast: 'Прогноз получен', get_inventory: 'Остатки проверены', get_in_transit: 'Товары в пути проверены', get_order_multiple: 'Кратность заказа проверена', get_bulk_adjustments: 'Всплески продаж проверены', list_replenishment_recommendations: 'План пополнения получен', list_review_required: 'Неполные данные найдены', list_transit_affected: 'Влияние поставок проверено', calculate_reorder: 'Количество пересчитано' };
    const workflow = agent.result ? `<ol class="agent-workflow">${[...new Set(agent.result.tools_used || [])].map(tool => `<li>${icon('check')}${esc(toolLabels[tool] || 'Проверка завершена')}</li>`).join('')}<li>${icon('user-round')}Ожидает проверки менеджером</li></ol>` : '';
    return `${header}<section class="section"><div class="section-head"><h2>Вопрос по закупкам</h2>${badge(!available ? 'Сервис недоступен' : local ? 'Локальный разбор, без OpenAI' : 'OpenAI настроен', available ? 'success' : 'warning')}</div><form id="intelligence-agent-form" class="intel-agent-form"><label>Ваш вопрос<textarea name="message" maxlength="4000" rows="4" required placeholder="Какие товары нужно закупить?" ${agent.busy ? 'disabled' : ''}>${esc(agent.message)}</textarea></label><button class="primary" type="submit" ${!available || agent.busy ? 'disabled' : ''}>${icon(agent.busy ? 'loader-circle' : 'send')}${agent.busy ? 'Анализируем…' : 'Отправить'}</button></form>${!available ? '<p class="muted">Не удалось получить актуальное состояние сервиса. Обновите результаты и повторите запрос.</p>' : ''}${agent.error ? `<p class="form-error" role="alert">${esc(agent.error)}</p>` : ''}${agent.result ? `<div class="ai-result" aria-live="polite"><h3>${agent.result.data?.mode === 'local' ? 'Локальный разбор, без OpenAI' : 'Разбор с OpenAI'}</h3>${agent.result.data?.reason === 'provider_unavailable' ? '<p class="form-error">OpenAI не ответил. Ниже только готовые расчёты, без ответа языковой модели.</p>' : ''}${workflow}<p class="intel-answer">${esc(agent.result.answer)}</p>${agent.result.requires_human_review ? badge('Нужна проверка менеджера', 'warning') : ''}</div>` : ''}</section>`;
  }
  const items = filteredItems(snapshot, query, filter);
  const tabs = [['all', `Все товары · ${all.length}`], ['replenishment', `К закупке · ${replenishment.length}`], ['review_required', `Уточнить · ${review.length}`], ['missing', `Без прогноза · ${missing.length}`]];
  return `${header}${page === 'forecast' ? stats : ''}<div class="tabs" role="tablist" aria-label="Статус расчёта">${tabs.map(([key, label]) => `<button role="tab" aria-selected="${filter === key}" class="${filter === key ? 'active' : ''}" data-action="intelligence-filter" data-filter="${key}">${esc(label)}</button>`).join('')}</div><div class="toolbar"><input id="search" type="search" aria-label="Товар, артикул или код" placeholder="Товар, артикул или код" value="${esc(query)}"><span class="muted">Показано ${items.length} из ${all.length} товаров</span><button class="icon-button ghost" data-action="intelligence-export" title="Скачать выбранный список CSV" aria-label="Скачать выбранный список CSV" ${!items.length ? 'disabled' : ''}>${icon('download')}</button></div>${page === 'procurement' ? supplierGroups(items) : table(items)}`;
}
