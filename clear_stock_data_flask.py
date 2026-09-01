#!/usr/bin/env python3
"""
Clear Stock Data Script for Flask App
Run this before re-importing stock data with the updated import logic

Usage:
    python clear_stock_data_flask.py
"""

import os
import sys

# Add parent directory to path
parent_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if parent_dir not in sys.path:
    sys.path.insert(0, parent_dir)

from api.database import db_session, init_db
from api.models import StockRecord, Dealership

def clear_stock_data():
    """Clear all stock records from database"""
    try:
        # Count before
        count_before = db_session.query(StockRecord).count()
        
        if count_before == 0:
            print("✅ Stock data already empty (0 records)")
            return True
        
        # Delete all stock records
        db_session.query(StockRecord).delete()
        db_session.commit()
        
        # Count after
        count_after = db_session.query(StockRecord).count()
        
        print(f"✅ Stock data cleared successfully!")
        print(f"   Records deleted: {count_before}")
        print(f"   Remaining records: {count_after}")
        print()
        print("📋 Next steps:")
        print("   1. Go to: http://localhost:5000/stock_report")
        print("   2. Upload your CSV or Excel file")
        print("   3. The import will now SKIP summary columns:")
        print("      ❌ CUC-UNPAID STOCK")
        print("      ❌ NORMAL-UNPAID STOCK")
        print("      ❌ PAID STOCK")
        print("      ❌ DIFFERENCE")
        print("      ❌ Any column with: UNPAID, PAID, DIFFERENCE, CUC, PENDING, STATUS, etc.")
        print("   4. Only real product variants will be imported:")
        print("      ✅ ALTO VXR")
        print("      ✅ CULTUS VXR")
        print("      ✅ SWIFT MT")
        print("      ✅ FRONX GLX")
        print("      ✅ etc.")
        
        return True
        
    except Exception as e:
        print(f"❌ Error clearing stock data: {str(e)}")
        db_session.rollback()
        return False

if __name__ == '__main__':
    print("🗑️  Clearing Stock Data...\n")
    success = clear_stock_data()
    sys.exit(0 if success else 1)
