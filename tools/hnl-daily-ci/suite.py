from pathlib import Path
import subprocess,os,time,json,xml.etree.ElementTree as ET,signal
P=Path('/evidence');start=time.monotonic();env=os.environ|{'HNL_EVIDENCE':'/tmp/hnl-local','TMPDIR':'/tmp/hnl-local','APP_ENV':'testing','CACHE_STORE':'array','QUEUE_CONNECTION':'sync','SESSION_DRIVER':'array','BROADCAST_CONNECTION':'null','LOG_CHANNEL':'null','DB_CONNECTION':'sqlite','DB_DATABASE':':memory:'}
Path('/tmp/hnl-local').mkdir(mode=0o770)
scenarios='migration concurrent-claim manual-scheduler crash-beforeclaim crash-afterclaim crash-beforehttp crash-uncertain-http crash-afterresponse crash-midwrite crash-precommit crash-lostack crash-postcommit lock-timeout deadlock identity-drift membership-drift lease-overtake lease-expiry-overtake midnight-before midnight-reserve lease-lost network changed-nochange'.split()
def run(args,limit,out):
 p=subprocess.Popen(args,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,start_new_session=True)
 try:b=p.communicate(timeout=limit-1)[0]
 except subprocess.TimeoutExpired:
  os.killpg(p.pid,signal.SIGKILL);b=p.communicate(timeout=1)[0];(P/(out+'.stdout')).write_bytes(b);raise RuntimeError('scenario_deadline')
 (P/(out+'.stdout')).write_bytes(b)
 assert p.returncode==0,(out,p.returncode)
for s in scenarios:
 assert time.monotonic()-start<650
 run(['php','-d','zend.exception_ignore_args=1','tools/hnl-daily-ci/engine.php',s],30,s)
regstart=time.monotonic();results=[]
for name,args in [('daily',['tests/Unit/HnlDailyRefreshTest.php']),('transport',['tools/hnl-daily-ci/TransportTest.php']),('stale',['tools/hnl-daily-ci/StaleFixtureGatewayTest.php','--filter','test_retries|test_429|test_malformed|test_extra'])]:
 run(['php','-d','zend.exception_ignore_args=1','vendor/bin/phpunit','--bootstrap','tools/hnl-daily-ci/test-bootstrap.php',*args,'--log-junit',str(P/(name+'.xml')),'--do-not-cache-result'],min(30,60-(time.monotonic()-regstart)),name)
 x=ET.parse(P/(name+'.xml')).getroot().find('testsuite');results.append({'suite':name,**{k:int(x.get(k)) for k in ['tests','assertions','failures','errors']}})
assert time.monotonic()-regstart<60
assert [x['tests'] for x in results]==[62,8,4]
(P/'regressions.json').write_text(json.dumps({'result':'PASS','suites':results,'unique_tests':sum(x['tests'] for x in results),'assertions':sum(x['assertions'] for x in results)}))
(P/'result.json').write_text(json.dumps({'result':'ENGINE_AND_RELEVANT_TESTS_PASS_PENDING_READBACK','cases':scenarios,'provider_requests':0,'elapsed':time.monotonic()-start}))
