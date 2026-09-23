# hack-87882dff-atks
Hackathon team repository for ATKS

# SupplyMind AI

> Agentic AI-система для прогнозирования спроса и автоматического расчёта заказов поставщикам.

**HackAlem AI 2026 — Logistics Track**

---

## 1. О проекте

SupplyMind AI — интеллектуальная система управления пополнением складских запасов.

Основная задача системы — помочь менеджеру отдела закупок автоматически определить:

- какой товар необходимо заказать;
- когда необходимо сделать заказ;
- какое количество необходимо заказать;
- у какого поставщика сформировать заказ;
- насколько срочной является закупка;
- почему система рекомендует именно такое количество.

Система объединяет складские данные, историю продаж, машинное обучение, алгоритмы управления запасами и Agentic AI в единый автоматизированный процесс.

SupplyMind AI не заменяет менеджера закупок.

Система формирует объяснимую рекомендацию и проект заказа, после чего ответственное лицо может проверить, скорректировать и подтвердить решение.

---

# 2. Проблема

В текущем процессе потребность в пополнении склада рассчитывается менеджером вручную на основе данных из Excel и учётных систем.

Такой подход имеет несколько проблем:

- расчёт занимает значительное время;
- невозможно постоянно пересчитывать потребность для большого количества SKU;
- возникают избыточные запасы;
- возникают stockout и упущенные продажи;
- сезонность может учитываться недостаточно точно;
- крупные разовые продажи могут искусственно увеличивать прогноз;
- фактические продажи во время отсутствия товара не отражают реальный спрос;
- сложно объяснить, почему необходимо заказать конкретное количество товара.

SupplyMind AI автоматизирует этот процесс.

---

# 3. Основная идея

Система строит полный цикл принятия решения:

```text
История продаж
      +
Текущие остатки
      +
Товары в пути
      +
Поставщики
      +
Категории товаров
      ↓
Data Validation
      ↓
Outlier Detection
      ↓
One-Time Order Detection
      ↓
Stockout Detection
      ↓
Lost Demand Estimation
      ↓
Seasonality / Trend Analysis
      ↓
Demand Forecasting
      ↓
Reorder Engine
      ↓
Agentic AI
      ↓
Purchase Recommendation
      ↓
Manager Review
      ↓
Approval
```

---

# 4. Архитектура

```text
                        SupplyMind AI
                              │
              ┌───────────────┴───────────────┐
              │                               │
       Mobile Application                Web Admin
         React Native                  Manager Panel
              │                               │
              └───────────────┬───────────────┘
                              │
                         PHP REST API
                              │
                            MySQL
                              │
                ┌─────────────┴─────────────┐
                │                           │
           Operational Data          Python Intelligence
                                            │
                                  Data Processing
                                            │
                                  Demand Forecasting
                                            │
                                   Reorder Engine
                                            │
                                      AI Agent
                                            │
                                   Recommendation
                                            │
                                       PHP API
                                            │
                                      Web Admin
```

Компоненты взаимодействуют через REST API и JSON.

---

# 5. Основные компоненты

## 5.1 Web Admin Panel

Веб-панель предназначена для менеджера отдела закупок.

Основные разделы:

- Dashboard
- Inventory
- Products
- Sales
- Forecast
- Procurement
- Suppliers
- Purchase Orders
- Analytics
- AI Agent

Dashboard показывает:

- общее количество SKU;
- состояние запасов;
- позиции с риском дефицита;
- потенциальный overstock;
- товары в пути;
- прогнозируемый спрос;
- рекомендации по закупке;
- срочность закупок.

---

# 6. Mobile Warehouse Application

Мобильное приложение используется сотрудниками склада.

Планируемые операции:

- Receiving;
- Outgoing;
- Transfer;
- Write-off;
- Inventory.

Камера смартфона используется для сканирования Barcode/QR.

Пример:

```text
SCAN BARCODE

       ↓

SKU: CABLE-001
NYM 3x2.5

Current stock:
42

Operation:
RECEIVING

Quantity:
20

[ CONFIRM ]
```

После подтверждения операция отправляется в PHP REST API и сохраняется в базе данных.

---

# 7. Backend

Основной backend реализуется на PHP.

Backend отвечает за:

- authentication;
- users;
- products;
- categories;
- warehouses;
- inventory;
- inventory movements;
- sales;
- suppliers;
- incoming shipments;
- recommendations;
- purchase orders;
- manager approval;
- интеграцию с ML/AI service.

Основная база данных:

**MySQL**

---

# 8. Data Pipeline

ML/AI часть реализуется на Python.

Перед прогнозированием данные проходят preprocessing.

```text
RAW DATA
   ↓
Schema Validation
   ↓
Missing Values
   ↓
Duplicates
   ↓
Outlier Detection
   ↓
Stockout Detection
   ↓
One-Time Order Detection
   ↓
Lost Demand Estimation
   ↓
Feature Engineering
   ↓
CLEAN DATASET
```

---

# 9. One-Time Order Detection

Одна из ключевых проблем — крупные разовые заказы.

Например:

```text
Regular demand:

10
12
9
11
8
13

One-time order:

450
```

Если использовать значение `450` как обычный спрос, модель может значительно завысить будущую потребность.

SupplyMind анализирует:

- SKU;
- quantity;
- historical distribution;
- anonymized customer ID;
- историю поведения клиента;
- частоту подобных заказов.

После этого операция может быть классифицирована как:

```text
REGULAR_DEMAND
```

или:

```text
ONE_TIME_ORDER
```

Разовый заказ исключается из расчёта регулярной потребности, но сохраняется в исходной истории.

---

# 10. Stockout & Lost Demand

Продажи не всегда равны реальному спросу.

Если товара не было на складе:

```text
Sales

Jan    100
Feb    110
Mar      0   ← STOCKOUT
Apr      0   ← STOCKOUT
May    120
```

значение `0` не означает отсутствие спроса.

SupplyMind определяет периоды stockout и оценивает потенциальный упущенный спрос на основе:

- предыдущих продаж;
- сезонности;
- тренда;
- поведения аналогичных периодов.

Получаем:

```text
Observed demand:
0

Estimated lost demand:
115
```

Скорректированный спрос используется для обучения forecasting model.

---

# 11. Seasonality & Trend

Система анализирует временные закономерности спроса:

- day of week;
- week;
- month;
- quarter;
- seasonal patterns;
- rolling statistics;
- historical growth;
- long-term trend.

Это позволяет отличать устойчивое увеличение спроса от временного всплеска.

---

# 12. Demand Forecasting

После очистки и подготовки данных строится модель прогнозирования спроса.

Планируется сравнить несколько подходов:

### Baseline

- Moving Average
- Seasonal Average

### Machine Learning

- CatBoost
- LightGBM
- XGBoost

### Deep Learning — при необходимости

- PyTorch
- временные neural forecasting models

Выбор production-модели будет выполняться по результатам time-based validation.

Потенциальные метрики:

- MAE
- RMSE
- WAPE / MAPE при применимости

Используется временное разделение train/validation, чтобы избежать data leakage.

---

# 13. Feature Engineering

Возможные признаки:

```text
SKU

Category

Price

Day of week
Week
Month
Quarter

Lag 1
Lag 7
Lag 14
Lag 28

Rolling mean 7
Rolling mean 14
Rolling mean 28

Seasonality

Trend

Growth

Corrected demand

Stockout information
```

Набор признаков будет уточняться после анализа предоставленного dataset.

---

# 14. NVIDIA Brev

Для тяжёлых ML/DL экспериментов может использоваться NVIDIA Brev GPU infrastructure.

Планируемая схема:

```text
Dataset
   ↓
NVIDIA Brev
   ↓
GPU Training
   ↓
Model Experiments
   ↓
Validation
   ↓
Best Model
   ↓
Model Artifact
```

GPU используется там, где это даёт измеримое преимущество по скорости экспериментов или качеству модели.

---

# 15. Reorder Engine

Forecasting Model отвечает на вопрос:

> Сколько товара, вероятно, понадобится?

Reorder Engine отвечает на вопрос:

> Сколько товара необходимо заказать сейчас?

При расчёте используются:

```text
Forecast Demand
+
Safety Stock
-
Current Stock
-
Goods In Transit
+
Supplier Constraints
+
Lead Time
```

Базовая концепция:

```text
Required Inventory =
Forecast Demand + Safety Stock

Available Inventory =
Current Stock + In Transit

Reorder Quantity =
Required Inventory - Available Inventory
```

После этого применяются дополнительные ограничения:

- MOQ;
- package size;
- supplier lead time;
- доступность товара;
- другие условия поставщика.

---

# 16. Agentic AI

SupplyMind использует AI Agent не как обычный chatbot, а как оркестратор системы.

Agent получает набор специализированных tools:

```text
load_sales_data()

validate_data()

detect_one_time_orders()

detect_stockouts()

estimate_lost_demand()

analyze_seasonality()

forecast_demand()

get_inventory()

get_in_transit()

get_supplier_terms()

calculate_reorder()

generate_explanation()

create_purchase_draft()
```

Пример workflow:

```text
Manager starts procurement calculation
             ↓
AI Agent
             ↓
Load data
             ↓
Validate data
             ↓
Detect one-time orders
             ↓
Detect stockouts
             ↓
Estimate lost demand
             ↓
Analyze seasonality
             ↓
Forecast demand
             ↓
Check inventory
             ↓
Check incoming goods
             ↓
Check supplier terms
             ↓
Calculate reorder
             ↓
Generate explanation
             ↓
Create purchase recommendation
```

LLM не выполняет математические расчёты самостоятельно.

Расчёты выполняются специализированными ML и deterministic tools.

Agent управляет последовательностью действий, анализирует результаты и формирует объяснение.

---

# 17. Explainable AI

Каждая рекомендация должна быть объяснима.

Пример:

```text
Product:
NYM 3x2.5

SKU:
CABLE-001

Current Stock:
42

In Transit:
20

Forecast Demand:
137

Recovered Lost Demand:
18

One-Time Order Excluded:
250

Supplier Lead Time:
14 days

Recommended Order:
100 units

Urgency:
HIGH
```

Пример объяснения:

> Available inventory including incoming goods is insufficient to cover expected regular demand during the supplier lead time. The forecast accounts for seasonality and estimated lost demand, while an identified one-time bulk purchase was excluded from regular demand.

---

# 18. Purchase Approval

SupplyMind не отправляет заказ поставщику автоматически.

Система создаёт:

```text
PURCHASE RECOMMENDATION
        ↓
PURCHASE DRAFT
        ↓
MANAGER REVIEW
        ↓
EDIT IF REQUIRED
        ↓
APPROVE
```

Финальное решение остаётся за ответственным сотрудником.

---

# 19. API Integration

Все компоненты интегрируются через REST API.

Пример результата ML Engine:

```json
{
  "sku": "CABLE-001",
  "supplier": "Supplier A",

  "current_stock": 42,
  "in_transit": 20,

  "forecast_demand": 137,

  "lost_demand": 18,

  "excluded_one_time_order": 250,

  "stockout_days": 4.2,

  "recommended_quantity": 100,

  "urgency": "HIGH",

  "explanation": "Available inventory is insufficient for expected regular demand during supplier lead time."
}
```

---

# 20. ML API

Python intelligence service предоставляет REST API через FastAPI.

Планируемые endpoints:

```text
GET  /health

POST /analyze

POST /forecast

POST /reorder

POST /agent/run
```

Архитектура интеграции:

```text
PHP
 ↓
FastAPI
 ↓
Python ML
 ↓
Forecast
 ↓
Reorder Engine
 ↓
AI Agent
 ↓
JSON
 ↓
PHP
 ↓
Web Dashboard
```

---

# 21. Experimental Edge AI Module

После реализации основного сценария планируется дополнительный модуль физической проверки складских остатков.

Используемое оборудование:

- NVIDIA Jetson Orin Nano;
- Intel RealSense D455;
- CUDA;
- PyTorch;
- YOLO11.

Архитектура:

```text
Physical Warehouse
        ↓
Intel RealSense D455
        ↓
Jetson Orin Nano
        ↓
YOLO11 / CUDA
        ↓
Object Detection
        ↓
Physical Inventory
        ↓
SupplyMind API
```

Пример:

```text
Digital Inventory:
20

Physical Inventory:
16

Difference:
-4
```

После обнаружения расхождения Reorder Engine может выполнить повторный расчёт с учётом подтверждённого физического остатка.

Этот модуль является дополнительным расширением основной системы и не заменяет данные учётной системы.

---

# 22. Feedback Loop

SupplyMind рассчитан на постоянное улучшение модели.

```text
Forecast
   ↓
Purchase Recommendation
   ↓
Actual Sales
   ↓
Actual Demand
   ↓
New Historical Data
   ↓
Dataset
   ↓
Model Evaluation
   ↓
Retraining
   ↓
New Candidate Model
   ↓
Validation
   ↓
Improved Forecast
```

Новая модель должна заменить production model только после проверки качества на historical/time-based validation.

---

# 23. Technology Stack

### Design

- Figma

### Mobile

- React Native
- Barcode / QR Scanner

### Web

- HTML
- CSS
- JavaScript
- Tailwind CSS

### Backend

- PHP
- REST API

### Database

- MySQL

### Data / ML

- Python
- Pandas
- NumPy
- scikit-learn
- CatBoost
- LightGBM
- XGBoost
- PyTorch — при необходимости

### ML API

- FastAPI

### Agentic AI

- OpenAI API
- Tool Calling

### Training Infrastructure

- NVIDIA Brev
- NVIDIA GPU

### Optional Edge AI

- NVIDIA Jetson Orin Nano
- Intel RealSense D455
- YOLO11
- CUDA
- PyTorch
- librealsense

### Development

- Git
- GitHub

---

# 24. Repository Structure

```text
SupplyMind/
│
├── backend/
│   ├── api/
│   ├── controllers/
│   ├── models/
│   └── config/
│
├── web/
│   ├── dashboard/
│   ├── inventory/
│   ├── procurement/
│   ├── suppliers/
│   └── orders/
│
├── mobile/
│   └── React Native application
│
├── intelligence/
│   │
│   ├── data/
│   │
│   ├── preprocessing/
│   │
│   ├── outliers/
│   │
│   ├── stockout/
│   │
│   ├── forecasting/
│   │
│   ├── reorder/
│   │
│   ├── agent/
│   │
│   └── api/
│
├── edge/
│   ├── realsense/
│   ├── yolo/
│   └── inventory/
│
├── docs/
│   ├── architecture/
│   └── screenshots/
│
├── .env.example
├── .gitignore
├── requirements.txt
└── README.md
```

---

# 25. Hackathon MVP

В рамках HackAlem основной MVP должен продемонстрировать полный сценарий:

```text
Sales / Inventory Data
        ↓
Data Validation
        ↓
One-Time Order Detection
        ↓
Stockout Detection
        ↓
Lost Demand Estimation
        ↓
Seasonality Analysis
        ↓
Demand Forecast
        ↓
Current Stock
        +
Goods In Transit
        +
Supplier Lead Time
        ↓
Reorder Calculation
        ↓
AI Agent
        ↓
Explainable Recommendation
        ↓
Web Dashboard
        ↓
Manager Approval
```

Дополнительно при наличии времени:

```text
Barcode Scan
      ↓
Mobile App
      ↓
Inventory Update
```

и:

```text
RealSense D455
      ↓
Jetson Orin Nano
      ↓
Physical Inventory Verification
      ↓
Reorder Recalculation
```

---

# 26. Expected Result

SupplyMind AI должен превратить ручной процесс:

```text
Excel
+
Manual Analysis
+
Human Calculation
```

в управляемый процесс:

```text
Operational Data
      ↓
Data Intelligence
      ↓
Demand Forecasting
      ↓
Inventory Optimization
      ↓
Agentic AI
      ↓
Explainable Procurement Recommendation
      ↓
Human Approval
```

Главная цель проекта — уменьшить риск дефицита и избыточных запасов и дать менеджеру закупок инструмент, который не только рекомендует количество заказа, но и показывает, на основании каких данных и расчётов была сформирована рекомендация.