"""Check the actual public_html/private layout without touching live accounts."""
from pathlib import Path
import tempfile, shutil, subprocess
root = Path(__file__).resolve().parents[1]
(root/'.local').mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(dir=root/'.local', prefix='cpanel-layout-') as folder:
    layout = Path(folder)
    (layout/'private').mkdir()
    (layout/'public_html').mkdir()
    for name in ['practice.php','journal.php','text.php','course-path.php','build-course.php','mastery.php']:
        shutil.copy2(root/'private'/name, layout/'private'/name)
    shutil.copy2(root/'public/course.json', layout/'public_html/course.json')
    script = """require 'private/practice.php';
if(count(course_catalog()['lessons'])!==18)throw new RuntimeException('Missing classes');
$s=fresh_state();foreach(course_catalog()['lessons'][0]['units'] as $unit)$s=change_progress($s,['action'=>'section','lesson'=>0,'unit'=>$unit['id'],'answers'=>array_column($unit['questions'],'correct')]);
$s=import_progress(json_decode(json_encode($s),true));
if($s['completed']!==[0])throw new RuntimeException('Progress not preserved');
echo "PASS: cPanel public_html catalogue, quiz validation and progress import.\\n";
"""
    subprocess.run(['php','-r',script], cwd=layout, check=True)
    subprocess.run(['php','private/build-course.php'], cwd=layout, check=True)
    assert (layout/'public_html/course.js').read_text() == (root/'public/course.js').read_text()
    print('PASS: curriculum generator uses the cPanel web folder and preserves the catalogue.')
