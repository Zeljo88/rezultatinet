from pathlib import Path
import os,subprocess,base64,json,xml.etree.ElementTree as E
# Generated only inside this networkless disposable runner; never logged.
env=dict(os.environ);env['APP_KEY']='base64:'+base64.b64encode(os.urandom(32)).decode()
e=Path('/evidence');results=[]
root=E.parse('phpunit.xml'); suites=root.getroot().find('testsuites');suites.clear(); suite=E.SubElement(suites,'testsuite',name='Focused isolated regression')
for name in ['StaleFixturePolicyTest','StaleFixtureGatewayTest','StaleFixtureJournalTest','StaleFixturePersistenceTest','FixtureCalendarSyncTest','FixtureCalendarWindowTest','FixtureRepairFinalizerTest','MatchTimeTest','MatchTimeUiTest']:
 E.SubElement(suite,'file').text=str(next(Path('tests').rglob(name+'.php')))
root.write('focused-ci.xml')
for label,args in [
 ('mariadb',['tests/Feature/StaleFixtureMariaDbConcurrencyTest.php']),
 ('focused',['-c','focused-ci.xml'])]:
 cmd=['php','vendor/bin/phpunit','--colors=never','--log-junit',str(e/(label+'.xml'))]+args
 r=subprocess.run(cmd,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
 (e/(label+'.txt')).write_text(r.stdout)
 result={'gate':label,'exit':r.returncode,'tail':r.stdout[-3500:]}
 if (e/(label+'.xml')).exists():
  root=E.parse(e/(label+'.xml')).getroot();suite=root.find('testsuite');result['totals']=suite.attrib; result['problems']=[{'test':c.get('name'),'message':(v.text or '')[:1500]} for c in root.findall('.//testcase') for v in list(c) if v.tag in ['failure','error']][:6]
  if int(suite.get('skipped','0')) or int(suite.get('tests','0'))!={'mariadb':20,'focused':157}[label]:result['exit']=1
 results.append(result);print(json.dumps(result))
(e/'test-results.json').write_text(json.dumps(results,indent=2))
if any(r['exit'] for r in results):raise SystemExit(1)
