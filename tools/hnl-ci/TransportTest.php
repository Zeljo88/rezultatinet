<?php
use PHPUnit\Framework\TestCase;
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\Factory;
use App\Contracts\ApiFootballQuotaStore;
use App\Services\ApiFootball\ApiFootballGateway;
require_once __DIR__.'/preview-transport.php';
final class TransportTest extends TestCase {
 protected function setUp():void {
  $c=new Container;Container::setInstance($c);Facade::clearResolvedInstances();Facade::setFacadeApplication($c);
  $c->instance('config',new Repository(['api_football'=>['enabled_sports'=>['football'],'base_url'=>'https://provider.invalid','max_attempts'=>2,'retry_base_ms'=>0,'timeout'=>1],'services'=>['api_football'=>['key'=>'']]]));
  $log=Mockery::mock();$log->shouldReceive('channel')->andReturnSelf();$log->shouldReceive('info','log')->andReturnNull();Log::swap($log);
  $cache=Mockery::mock();$cache->shouldReceive('store')->andReturnSelf();$cache->shouldReceive('add')->andReturnTrue();Cache::swap($cache);
 }
 protected function tearDown():void {Mockery::close();Facade::clearResolvedInstances();}
 #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
 public function test_single_attempt($status,$body,$deny=false):void {
  $quota=Mockery::mock(ApiFootballQuotaStore::class);
  $quota->shouldReceive('reserve')->once()->with('standings','HnlCoveragePreview20261021')->andReturn(['allowed'=>!$deny,'reason'=>'circuit']);
  $quota->shouldReceive('record')->times($deny?0:1);
  $quota->shouldReceive('openCircuit')->times(!$deny&&$status===429?1:0);
  $factory=new Factory;$requests=0;
  $factory->fake(function($request,$options)use($status,$body,&$requests){
   $requests++;self::assertFalse($options['allow_redirects']);
   self::assertSame('https://provider.invalid/standings?league=210&season=2026',$request->url());
   if($status===0)throw new \Illuminate\Http\Client\ConnectionException('test');
   return Factory::response($body,$status,['Location'=>'https://must-not-follow.invalid']);
  });
  $o=hnlPreviewTransport(new ApiFootballGateway($quota),$factory);
  self::assertSame($deny?0:1,$requests);
  self::assertLessThanOrEqual(2,$o['claim_hook_invocations']);
  if(!$deny&&in_array($status,[0,500]))self::assertSame(\App\Exceptions\ApiFootballLocalAttemptLimitReached::class,$o['error_type']);
 }
 public static function cases():array {
  $ok=['get'=>'standings','errors'=>[],'results'=>1,'paging'=>['current'=>1,'total'=>1],'response'=>[['league'=>['id'=>210,'season'=>2026]]]];
  return ['success'=>[200,$ok],'500 retry denied'=>[500,[]],'network retry denied'=>[0,[]],'429 stop'=>[429,[]],'redirect no follow'=>[302,[]],'malformed'=>[200,'bad-json'],'provider error'=>[200,['errors'=>['rate'=>'test'],'response'=>[]]],'breaker denial'=>[200,$ok,true]];
 }
}
