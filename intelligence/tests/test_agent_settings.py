import os
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

from intelligence.agent.settings import settings


class SettingsTests(unittest.TestCase):
    def test_dedicated_file_only_and_hot_reload(self):
        with TemporaryDirectory() as directory:
            env_file = Path(directory) / '.env'
            with patch('intelligence.agent.settings.ENV_FILE', env_file), patch.dict(os.environ, {'OPENAI_API_KEY': 'unrelated-runtime-key'}):
                self.assertEqual(settings()['key'], '')
                env_file.write_text('OPENAI_API_KEY="fixture-key"\nOPENAI_MODEL=fixture-model\n', encoding='utf-8-sig')
                self.assertEqual(settings(), {'key': 'fixture-key', 'model': 'fixture-model'})
                env_file.write_text('OPENAI_API_KEY=\n', encoding='utf-8')
                self.assertEqual(settings()['key'], '')

    def test_invalid_encoding_keeps_local_mode(self):
        with TemporaryDirectory() as directory:
            env_file = Path(directory) / '.env'
            env_file.write_bytes(b'\xff\xff')
            with patch('intelligence.agent.settings.ENV_FILE', env_file):
                self.assertEqual(settings()['key'], '')
