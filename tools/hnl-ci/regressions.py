from pathlib import Path
import subprocess,os,sys,json,xml.etree.ElementTree as ET
out=Path(os.environ.get('HNL_OUT','tools/hnl-ci/out'));out.mkdir(parents=True,exist_ok=True)
suites=[('supplied-hnl',['tests/Unit/HnlStandingsSyncTest.php'],35,288),('retained-independent',['tests/Unit/HnlIndependentReviewTest.php'],40,354),('venue-six',['tests/Unit/HnlVenueBalanceReconstructionTest.php'],13,282),('fake-preview',['tools/hnl-ci/TransportTest.php'],8,40),('gateway',['tools/hnl-ci/StaleFixtureGatewayTest.php','--filter','test_retries|test_429|test_malformed|test_extra'],4,23)]
results=[];ids=[]
for name,args,count,asserts in suites:
 xml=out/(name+'.xml')
 cmd=['php','-d','zend.exception_ignore_args=1','vendor/bin/phpunit','--bootstrap','tools/hnl-ci/test-bootstrap.php','--do-not-cache-result',*args,'--log-junit',str(xml)]
 p=subprocess.run(cmd,env=os.environ|{'HNL_EVIDENCE':str(out)},capture_output=True,text=True,timeout=30)
 (out/(name+'.log')).write_text(p.stdout+'\n'+p.stderr);print(p.stdout,flush=True)
 if p.returncode:sys.exit(p.returncode)
 cases=ET.parse(xml).findall('.//testcase');assert len(cases)==count and sum(int(x.attrib['assertions']) for x in cases)==asserts,(name,'count mismatch')
 ids += [(x.attrib['class'],x.attrib['name']) for x in cases]
 results.append({'suite':name,'tests':count,'assertions':asserts,'exit':0})
assert len(ids)==len(set(ids))==100
(out/'regressions.json').write_text(json.dumps({'result':'PASS','unique_tests':100,'assertions':987,'suites':results,'engine_note':'Existing SQLite/fake regressions, separate from real MariaDB engine scenarios','not_full_suite':True},indent=2)+'\n')
