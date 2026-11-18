"""Sterile INI for in-memory canonical cURL simulation, never real provider access."""
from pathlib import Path
MODULES=['ctype','dom','fileinfo','iconv','mbstring','pdo','pdo_pgsql','phar','posix','sodium','tokenizer','xml','xmlwriter','curl']
def render(ext,builtins):
 ext=Path(ext)
 if not ext.is_absolute() or not ext.is_dir():raise ValueError('INI_REFUSED')
 lines=['display_errors=0','log_errors=0','memory_limit=256M','date.timezone=UTC','auto_prepend_file=','auto_append_file=',
 'allow_url_fopen=0','allow_url_include=0',
 'disable_functions=curl_exec,curl_multi_exec,curl_multi_socket_action,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,socket_create,socket_connect,socket_bind']
 for name in MODULES:
  if name.lower() not in [n.lower() for n in builtins]:
   p=ext/(name+'.so')
   if not p.is_file():raise ValueError('INI_REFUSED')
   lines.append('extension='+str(p))
 return '\n'.join(lines)+'\n'
