"""All discipline sections are covered by the full ordered curriculum test.
Requires the disposable http://localhost:8791 development server/config.
"""
import runpy
from pathlib import Path
runpy.run_path(str(Path(__file__).with_name('full_study_api.py')),run_name='__main__')
