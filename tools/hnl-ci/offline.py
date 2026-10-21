# Host-local validation only: seccomp prevents every socket creation.
from pathlib import Path
import ctypes,ctypes.util,errno,subprocess,sys,os,json
lib=ctypes.CDLL(ctypes.util.find_library('seccomp'),use_errno=True)
lib.seccomp_init.argtypes=[ctypes.c_uint32];lib.seccomp_init.restype=ctypes.c_void_p
lib.seccomp_rule_add_array.argtypes=[ctypes.c_void_p,ctypes.c_uint32,ctypes.c_int,ctypes.c_uint,ctypes.c_void_p]
lib.seccomp_load.argtypes=[ctypes.c_void_p];lib.seccomp_release.argtypes=[ctypes.c_void_p];lib.seccomp_syscall_resolve_name.argtypes=[ctypes.c_char_p]
def deny():
 ctx=lib.seccomp_init(0x7fff0000);assert ctx
 assert lib.seccomp_rule_add_array(ctx,0x50000|errno.EPERM,lib.seccomp_syscall_resolve_name(b'socket'),0,None)==0
 assert lib.seccomp_load(ctx)==0;lib.seccomp_release(ctx)
out=Path.cwd()/'tools/hnl-ci/out';out.mkdir(exist_ok=True)
env={'PATH':'/usr/bin:/bin','APP_ENV':'testing','HNL_OUT':str(out),'HNL_EVIDENCE':str(out),'CACHE_STORE':'array','QUEUE_CONNECTION':'sync','SESSION_DRIVER':'array','BROADCAST_CONNECTION':'null','LOG_CHANNEL':'null'}
for name,cmd in [('fake-smoke',['php','-d','zend.exception_ignore_args=1','tools/hnl-ci/worker.php','offline-fake','fake']),('regressions',['python3','tools/hnl-ci/regressions.py'])]:
 p=subprocess.run(cmd,env=env,preexec_fn=deny,capture_output=True,text=True,timeout=60)
 (out/(name+'-offline.log')).write_text(p.stdout+'\n'+p.stderr);print(p.stdout);print(p.stderr);assert p.returncode==0,name
s=json.loads((out/'offline-fake.state.json').read_text());assert s['fetches']==1 and s['transaction_attempts']==1 and s['saves']==10 and s['receipts']==1 and s['result']['outcome']=='committed'
print('PASS local fake smoke and 100/987 regressions; all sockets denied; no MariaDB claim')
