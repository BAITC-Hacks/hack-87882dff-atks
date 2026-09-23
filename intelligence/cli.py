"""Run with python -m intelligence.cli."""
import argparse
import json

from .loader import DATA_DIR, run_pipeline


def main():
    parser = argparse.ArgumentParser(description="Inspect and normalize System Electric Excel data")
    parser.add_argument("--raw-dir", type=str, default=str(DATA_DIR / "raw"))
    parser.add_argument("--output-dir", type=str, default=str(DATA_DIR / "processed"))
    args = parser.parse_args()
    try:
        report = run_pipeline(args.raw_dir, args.output_dir)
    except (OSError, ValueError) as exc:
        parser.exit(1, f"Preprocessing failed: {exc}\n")
    print(f"Transaction count: {report['transaction_count']:,}")
    print(f"SKU count (all datasets): {report['sku_count']:,}")
    print(f"Transaction SKU count: {report['transaction_sku_count']:,}")
    print(f"Date range (transactions): {report['date_range'][0]} — {report['date_range'][1]}")
    print(f"Warehouse count: {report['warehouse_count']}")
    movements = report['datasets']['transactions']['source']['movement_counts']
    print("Transaction movements: " + json.dumps(movements, ensure_ascii=False))
    if movements.get('positive_movement', 0):
        print("REVIEW: Positive transaction quantities have unclassified demand. "
              "Confirm their meaning before using this dataset for demand analysis.")
    for name, stats in report["datasets"].items():
        missing = {k: int(v) for k, v in stats['missing_values'].items() if v}
        print(f"{name}: {stats['row_count']:,} rows; duplicate count: {stats['duplicate_count']}; "
              f"duplicate key count: {stats['duplicate_key_count']}")
        print("  Missing values: " + json.dumps(missing, ensure_ascii=False))
    print(f"Saved normalized datasets and reports to {args.output_dir}")


if __name__ == "__main__":
    main()
