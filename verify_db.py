import os
import sys
from dotenv import load_dotenv

# Resolve root path of the project and load .env file
root_dir = os.path.dirname(os.path.abspath(__file__))
load_dotenv(os.path.join(root_dir, '.env'))

# Add project root to sys.path so we can import modules
sys.path.insert(0, root_dir)

from api.database import init_db, db_session
from api.models import User, Dealership

def verify():
    print("Initializing Database...")
    try:
        init_db()
        print("Database schema initialized/verified.")
        
        # Check users count
        num_users = db_session.query(User).count()
        print(f"Total Users in DB: {num_users}")
        
        # Check dealerships count
        num_dealers = db_session.query(Dealership).count()
        print(f"Total Dealerships in DB: {num_dealers}")
        
        print("\nAll DB Connection & Table verifications PASSED!")
    except Exception as e:
        print(f"\nDATABASE VERIFICATION FAILED: {str(e)}", file=sys.stderr)
        sys.exit(1)

if __name__ == '__main__':
    verify()
