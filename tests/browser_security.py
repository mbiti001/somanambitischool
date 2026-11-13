"""Browser response policy checks against disposable localhost only."""
from pathlib import Path
import urllib.request
root=Path(__file__).resolve().parents[1]
with urllib.request.urlopen('http://localhost:8799/session.php') as r:
 h=r.headers;csp=h.get('Content-Security-Policy','')
 for clause in ["script-src 'self'", "connect-src 'self'", "object-src 'none'", "base-uri 'none'", "form-action 'self'", "frame-ancestors 'none'", "media-src 'self' blob:", 'frame-src https:']:assert clause in csp,clause
 assert "script-src 'self' 'unsafe-inline'" not in csp and "'unsafe-eval'" not in csp
 assert 'microphone=(self)' in h.get('Permissions-Policy','') and 'camera=()' in h.get('Permissions-Policy','')
 assert h.get('X-Frame-Options')=='DENY' and h.get('X-Content-Type-Options')=='nosniff'
 assert h.get('Cache-Control')=='no-store, private'
 assert h.get('X-Powered-By') is None
apache=(root/'public/.htaccess').read_text();assert csp in apache
assert 'Strict-Transport-Security "max-age=2592000"' in apache
assert 'sql|sqlite3?|log|ini|env|bak|backup|old|orig|swp|zip|tar|gz|lock|map' in apache
assert '(?:private|database|tests|work|\\.git)' in apache
certificate=(root/'private/certificate-pdf.php').read_text();assert "script-src 'nonce-" in certificate
print('PASS: same-origin scripts/forms/connections, blocked inline/eval/objects/framing, readable HTTPS references, local rehearsal audio, compact permissions, no PHP version, matching static policy and protected source/backup extensions. Certificate retains its own nonce policy.')
