from pathlib import Path
import tempfile, subprocess, os, json, sys
root=Path(__file__).resolve().parents[1]
os.chdir(root)
(root/'work/mail-envelope-check').mkdir(parents=True,exist_ok=True)
with tempfile.TemporaryDirectory(dir=root/'work/mail-envelope-check') as td:
 p=Path(td); capture=p/'message.json'; fake=p/'sendmail'; config=p/'config.php'
 fake.write_text('#!'+sys.executable+'\nimport sys,json,os\nfrom pathlib import Path\nPath(os.environ["MAIL_CAPTURE"]).write_text(json.dumps({"args":sys.argv[1:],"body":sys.stdin.read()}))\n');fake.chmod(0o700)
 env={**os.environ,'HISA_CONFIG_PATH':str(config),'MAIL_CAPTURE':str(capture)}
 def run(sender):
  config.write_text('<?php return '+"['app_key'=>str_repeat('x',32),'environment'=>'development','mail_from'=>"+json.dumps(sender)+",'mail_transport'=>'mail'];")
  return subprocess.run(['php','-d','sendmail_path='+str(fake),'--run',"require 'private/bootstrap.php';echo send_account_mail('learner@example.test','Test sign-in delivery','Test body')?'sent':'failed';"],env=env,capture_output=True,text=True)
 r=run('info@somanambiti.com');assert r.returncode==0 and r.stdout=='sent',r.stderr
 m=json.loads(capture.read_text());assert '-finfo@somanambiti.com' in m['args'];assert 'From: info@somanambiti.com' in m['body'] and 'MIME-Version: 1.0' in m['body']
 for bad in ['info@somanambiti.com -X/tmp/log','info@somanambiti.com\r\nBcc:x@example.test','"quoted mailbox"@somanambiti.com']:
  assert run(bad).returncode!=0
 print('PASS: configured domain becomes envelope sender; valid MIME headers; unsafe sender values rejected. No external email sent.')
