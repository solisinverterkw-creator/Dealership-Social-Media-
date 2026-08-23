import bcrypt
from functools import wraps
from flask import session, redirect, url_for, abort, request
from api.database import db_session
from api.models import User, user_dealerships, UserSidebarSection

def hash_password(password: str) -> str:
    """Hash password using bcrypt, compatible with PHP password_hash."""
    salt = bcrypt.gensalt()
    # bcrypt expects bytes, and password_hash in PHP outputs a string
    hashed = bcrypt.hashpw(password.encode('utf-8'), salt)
    return hashed.decode('utf-8')

def verify_password(password: str, hashed: str) -> bool:
    """Verify password against a bcrypt hash."""
    if not hashed:
        return False
    try:
        # standard PHP hash format is string, bcrypt expects bytes
        return bcrypt.checkpw(password.encode('utf-8'), hashed.encode('utf-8'))
    except Exception:
        return False

def attempt_login(username, password) -> bool:
    """Authenticate user and store roles and permissions in the session."""
    user = db_session.query(User).filter(User.username == username).first()
    if user and verify_password(password, user.password_hash):
        session['user_id'] = user.id
        session['username'] = user.username
        session['is_super_admin'] = bool(user.is_super_admin)
        session['can_refresh'] = bool(user.can_refresh)
        session['can_edit'] = bool(user.can_edit)
        session['can_delete'] = bool(user.can_delete)

        # Get dealerships assigned to the user
        dealership_ids = []
        if not user.is_super_admin:
            dealership_ids = [d.id for d in user.dealerships]
        session['dealership_ids'] = dealership_ids

        # Get sidebar sections allowed
        sidebar_sections = []
        if not user.is_super_admin:
            sidebar_sections = [s.section_key for s in user.sidebar_sections]
        session['sidebar_sections'] = sidebar_sections

        return True
    return False

def logout_user():
    """Clear all session data."""
    session.clear()

def is_logged_in() -> bool:
    """Check if the user is currently logged in."""
    return 'user_id' in session

def is_super_admin() -> bool:
    """Check if the current logged-in user is a super admin."""
    return is_logged_in() and session.get('is_super_admin', False)

def get_dealership_ids() -> list:
    """Get the dealership IDs the current user has access to."""
    return session.get('dealership_ids', [])

def can_access_dealership(dealership_id: int) -> bool:
    """Check if the current user has access to a specific dealership."""
    if not is_logged_in():
        return False
    if is_super_admin():
        return True
    return int(dealership_id) in get_dealership_ids()

def can_view(section_key: str) -> bool:
    """Check if the current user can view a specific sidebar section."""
    if not is_logged_in():
        return False
    if is_super_admin():
        return True
    return section_key in session.get('sidebar_sections', [])

def can_perform(capability: str) -> bool:
    """
    Check if the user is authorized for a capability ('refresh', 'edit', 'delete').
    Super admins have all capabilities. Scoped users need the matching capability flag set.
    """
    if not is_logged_in():
        return False
    if is_super_admin():
        return True
    return bool(session.get(f'can_{capability}', False))

# Decorators
def require_login(f):
    @wraps(f)
    def decorated_function(*args, **kwargs):
        if not is_logged_in():
            return redirect(url_for('login'))
        return f(*args, **kwargs)
    return decorated_function

def require_super_admin(f):
    @wraps(f)
    def decorated_function(*args, **kwargs):
        if not is_logged_in():
            return redirect(url_for('login'))
        if not is_super_admin():
            abort(403, description="Only A Super Admin Can View This Page.")
        return f(*args, **kwargs)
    return decorated_function
