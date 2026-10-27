from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker, scoped_session, declarative_base
from api.config import Config

import ssl

# Fix for pg8000 + scramp incompatibility where scramp lacks make_channel_binding
try:
    import scramp
    if not hasattr(scramp, 'make_channel_binding'):
        if hasattr(scramp, 'core') and hasattr(scramp.core, 'make_channel_binding'):
            scramp.make_channel_binding = scramp.core.make_channel_binding
        else:
            scramp.make_channel_binding = lambda *args, **kwargs: None
except Exception:
    pass

connect_args = {}
if "postgresql+pg8000" in Config.DATABASE_URL:
    connect_args["ssl_context"] = ssl.create_default_context()

# Setup SQLAlchemy engine. Enable pre-ping to verify connection health before queries (great for serverless context)
engine = create_engine(
    Config.DATABASE_URL,
    pool_recycle=3600,
    pool_pre_ping=True,
    connect_args=connect_args
)

db_session = scoped_session(sessionmaker(autocommit=False, autoflush=False, bind=engine))

Base = declarative_base()
Base.query = db_session.query_property()

def init_db():
    import api.models
    Base.metadata.create_all(bind=engine)
