from pathlib import Path
import subprocess,os,sys,json,xml.etree.ElementTree as ET
import ctypes,signal,time

# All five suites, output validation and publication share this one deadline.
AGGREGATE_SECONDS=60
CLEANUP_RESERVE=2

def remaining(deadline):
 seconds=deadline-time.monotonic()
 if seconds<=0:raise TimeoutError('aggregate regression deadline exhausted')
 return seconds

def terminate_group(p,deadline):
 # Kill the whole session's process group even when its leader already exited.
 # As a Linux subreaper, also collect orphaned grandchildren in this group.
 for sig in [signal.SIGTERM,signal.SIGKILL]:
  try:os.killpg(p.pid,sig)
  except ProcessLookupError:pass
  if sig==signal.SIGTERM:
   try:p.wait(timeout=min(.25,max(0,deadline-time.monotonic())))
   except subprocess.TimeoutExpired:pass
 p.communicate(timeout=remaining(deadline))
 while True:
  remaining(deadline)
  try:pid,_=os.waitpid(-p.pid,os.WNOHANG)
  except ChildProcessError:return
  if pid==0:time.sleep(min(.01,remaining(deadline)))

def run_suite(cmd,deadline):
 # Reserve cleanup within BOTH the 30s suite bound and the 60s aggregate.
 end=min(time.monotonic()+30,deadline)
 remaining(end-CLEANUP_RESERVE)
 p=subprocess.Popen(cmd,env=os.environ|{'HNL_EVIDENCE':str(out)},stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,start_new_session=True)
 try:
  stdout,stderr=p.communicate(timeout=remaining(end-CLEANUP_RESERVE))
 finally:terminate_group(p,end)
 remaining(deadline-CLEANUP_RESERVE)
 return subprocess.CompletedProcess(cmd,p.returncode,stdout,stderr)

def main():
 global out
 deadline=time.monotonic()+AGGREGATE_SECONDS
 # Linux CI: fail closed if orphan reaping cannot be established.
 if ctypes.CDLL(None,use_errno=True).prctl(36,1,0,0,0)!=0:
  raise OSError(ctypes.get_errno(),'PR_SET_CHILD_SUBREAPER')
 def interrupted(signum,frame):raise TimeoutError('regressions interrupted')
 signal.signal(signal.SIGTERM,interrupted)
 out=Path(os.environ.get('HNL_OUT','tools/hnl-ci/out'));out.mkdir(parents=True,exist_ok=True)
 suites=[('supplied-hnl',['tests/Unit/HnlStandingsSyncTest.php'],35,288),('retained-independent',['tests/Unit/HnlIndependentReviewTest.php'],40,354),('venue-six',['tests/Unit/HnlVenueBalanceReconstructionTest.php'],13,282),('fake-preview',['tools/hnl-ci/TransportTest.php'],8,40),('gateway',['tools/hnl-ci/StaleFixtureGatewayTest.php','--filter','test_retries|test_429|test_malformed|test_extra'],4,23)]
 (out/'regressions.json').write_text('{"result":"INCONCLUSIVE"}\n')
 results=[];ids=[]
 for name,args,count,asserts in suites:
  remaining(deadline-CLEANUP_RESERVE)
  xml=out/(name+'.xml')
  cmd=['php','-d','zend.exception_ignore_args=1','vendor/bin/phpunit','--bootstrap','tools/hnl-ci/test-bootstrap.php','--do-not-cache-result',*args,'--log-junit',str(xml)]
  p=run_suite(cmd,deadline)
  (out/(name+'.log')).write_text(p.stdout+'\n'+p.stderr);print(p.stdout,flush=True)
  if p.returncode:sys.exit(p.returncode)
  cases=ET.parse(xml).findall('.//testcase');assert len(cases)==count and sum(int(x.attrib['assertions']) for x in cases)==asserts,(name,'count mismatch')
  ids += [(x.attrib['class'],x.attrib['name']) for x in cases]
  results.append({'suite':name,'tests':count,'assertions':asserts,'exit':0})
 assert len(ids)==len(set(ids))==100
 remaining(deadline-CLEANUP_RESERVE)
 (out/'regressions.json').write_text(json.dumps({'result':'PASS','unique_tests':100,'assertions':987,'suites':results,'engine_note':'Existing SQLite/fake regressions, separate from real MariaDB engine scenarios','not_full_suite':True},indent=2)+'\n')
 try:remaining(deadline-CLEANUP_RESERVE)
 except TimeoutError:
  (out/'regressions.json').write_text('{"result":"INCONCLUSIVE"}\n')
  raise

if __name__=='__main__':main()
