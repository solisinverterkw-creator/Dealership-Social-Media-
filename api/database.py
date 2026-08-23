from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker, scoped_session, declarative_base
from api.config import Config

# Setup SQLAlchemy engine. Enable pre-ping to verify connection health before queries (great for serverless context)
engine = create_engine(
    Config.DATABASE_URL,
    pool_recycle=3600,
    pool_pre_ping=True
)

db_session = scoped_session(sessionmaker(autocommit=False, autoflush=False, bind=engine))

Base = declarative_base()
Base.query = db_session.query_property()

def init_db():
    import api.models
    Base.metadata.create_all(bind=engine)
