"""Read only the dedicated server secret file; never inherit unrelated runtime keys."""
from pathlib import Path
from dotenv import dotenv_values

ENV_FILE = Path(__file__).resolve().parents[1] / '.env'


def settings():
    # Re-read for each request so adding/revoking a key does not require a restart.
    try:
        values = dotenv_values(ENV_FILE, interpolate=False, encoding='utf-8-sig') if ENV_FILE.is_file() else {}
    except (OSError, UnicodeError):
        # A file being edited or saved in an incompatible encoding must not break forecasts.
        values = {}
    return {
        'key': (values.get('OPENAI_API_KEY') or '').strip(),
        'model': (values.get('OPENAI_MODEL') or 'gpt-4.1-mini').strip(),
    }
