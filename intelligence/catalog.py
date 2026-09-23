"""Read-only product labels; never change forecasts or operational stock."""
import pandas as pd
from intelligence.loader import FILES, read_table


def build_catalog(frames, directory):
    names, articles, units = {}, {}, {}
    transaction_path = directory / 'transactions.csv'
    if transaction_path.is_file():
        labels = pd.read_csv(transaction_path, usecols=['sku', 'product_name', 'unit'],
                             dtype='string', keep_default_na=False)
        for row in labels.drop_duplicates('sku', keep='last').itertuples(index=False):
            if row.product_name:
                names[row.sku] = row.product_name
            if row.unit:
                units[row.sku] = row.unit
    for source, article_column in [('goods_in_transit', 'supplier_article'), ('moq', 'article')]:
        for row in frames[source].to_dict('records'):
            sku = row['sku']
            if pd.notna(row.get('product_name')):
                names[sku] = str(row['product_name'])
            if pd.notna(row.get(article_column)):
                articles[sku] = str(row[article_column])
    skus = set().union(*(set(frames[name].sku.dropna()) for name in
                        ('moq', 'goods_in_transit', 'monthly_inventory', 'forecasts')))
    inventory_path = directory.parent / 'raw' / FILES['monthly_inventory']
    if inventory_path.is_file():
        # The normalized monthly stock CSV omits the original names and units.
        labels = read_table(inventory_path, 'monthly_inventory', {})
        for row in labels.to_dict('records'):
            sku = row['sku']
            if sku in skus and pd.notna(row.get('product_name')):
                names.setdefault(sku, str(row['product_name']))
            if sku in skus and pd.notna(row.get('unit')):
                units.setdefault(sku, str(row['unit']))
    forecast_skus = set(frames['forecasts'].sku)
    return [{'sku': sku, 'product_name': names.get(sku), 'article': articles.get(sku),
             'unit': units.get(sku), 'has_forecast': sku in forecast_skus}
            for sku in sorted(skus)]
