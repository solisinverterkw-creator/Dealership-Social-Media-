#!/usr/bin/env python3
"""
URGENT: Clear all old stock data from database
Run this NOW before re-importing

This clears old 0-value columns that are cluttering the report
"""

import os
import sys

# Add parent directory to path
parent_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if parent_dir not in sys.path:
    sys.path.insert(0, parent_dir)

def clear_stock_data_now():
    """Immediately clear all stock records"""
    from api.database import db_session
    from api.models import StockRecord
    
    try:
        print("🔍 Checking database...")
        
        # Get count and show what's there
        all_records = db_session.query(StockRecord).all()
        count_before = len(all_records)
        
        if count_before == 0:
            print("✅ Database is already empty (0 records)")
            return
        
        print(f"\n📊 Found {count_before} old records:")
        
        # Show sample of what's being deleted
        product_summary = {}
        for rec in all_records[:100]:  # Show first 100
            if rec.product_name not in product_summary:
                product_summary[rec.product_name] = 0
            product_summary[rec.product_name] += 1
        
        for prod_name, count in sorted(product_summary.items()):
            print(f"   - {prod_name}: {count} record(s)")
        
        if len(all_records) > 100:
            print(f"   ... and {len(all_records) - 100} more records")
        
        # DELETE
        print("\n🗑️  Deleting all stock records...")
        db_session.query(StockRecord).delete()
        db_session.commit()
        
        # Verify
        count_after = db_session.query(StockRecord).count()
        
        print(f"\n✅ SUCCESS! Deleted {count_before} old records")
        print(f"   Remaining records: {count_after}")
        
        print("\n" + "="*60)
        print("🚀 NEXT STEPS:")
        print("="*60)
        print("\n1. Go to: https://dealership-social-media.vercel.app/stock_report")
        print("\n2. Upload your CSV or Excel file with Fort Motor data")
        print("\n3. The system will NOW:")
        print("   ✅ Skip summary columns (UNPAID, PAID, DIFFERENCE, etc.)")
        print("   ✅ Import ONLY real products (ALTO VXR, CULTUS VXR, etc.)")
        print("   ✅ Show actual counts, NOT zeros")
        print("\n4. Verify results - you should see:")
        print("   ✅ Clean dealership rows")
        print("   ✅ Product columns with real numbers")
        print("   ✅ NO zero-value summary columns")
        print("\n" + "="*60)
        
        return True
        
    except Exception as e:
        print(f"\n❌ ERROR: {str(e)}")
        import traceback
        traceback.print_exc()
        try:
            db_session.rollback()
        except:
            pass
        return False

if __name__ == '__main__':
    success = clear_stock_data_now()
    sys.exit(0 if success else 1)
