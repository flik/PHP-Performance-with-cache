<?php
// memcached -m 64 -s /tmp/m.sock -a 0777 -p 0 -u memcache
// memcached -m 64 -l 127.0.0.1 -p 11211 -u memcache
set_time_limit(3600);
error_reporting(E_ALL);
ini_set("display_errors", 1);
ini_set("apc.enable_cli", 1);
mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

$data = array( // test are range from 10-128,000 bytes
  "1111111110",
  str_repeat("1111111110", 10),
  str_repeat("1111111110", 30),
  str_repeat("1111111110", 50),
  str_repeat("1111111110", 100),
  str_repeat("1111111110", 200),
  str_repeat("1111111110", 400),
  str_repeat("1111111110", 800),
  str_repeat("1111111110", 1600),
  str_repeat("1111111110", 3200),
  str_repeat("1111111110", 6400),
  str_repeat("1111111110", 12800),
  array(0=>"1111111110", "id2"=>"hello world", "id3"=>"foo bar", "id4"=>42)
);

echo "shm\n".'<hr>';
$t = array();
foreach ($data as $key=>$val) $t[] = shm(4000+$key, $val);
echo stats($t);

echo "apc\n".'<hr>';
$t = array();
foreach ($data as $key=>$val) $t[] = apc((string)$key, $val);
echo stats($t);


echo "memcache\n".'<hr>';
$t = array();
$m = memcache_connect("127.0.0.1", 8080);
foreach ($data as $key=>$val) $t[] = memcache($m, (string)$key, $val);
echo stats($t);

echo "memcache socket\n".'<hr>';
$t = array();
$m = memcache_connect("unix:///tmp/m.sock", 0);
foreach ($data as $key=>$val) $t[] = memcache($m, (string)$key, $val);
echo stats($t);

echo "memcached\n".'<hr>';
$t = array();
$m = new Memcached();
$m->addServer("127.0.0.1", 11211);
foreach ($data as $key=>$val) $t[] = memcached($m, (string)$key, $val);
echo stats($t);

 //not in memcached 1.x
echo "memcached socket\n".'<hr>';
$t = array();
$m = new Memcached();
$m->addServer("unix:///tmp/m.sock", 0);
foreach ($data as $key=>$val) $t[] = memcached($m, (string)$key, $val);
echo stats($t);
/**/

echo "mysql myisam\n".'<hr>';
$t = array();
$m = new mysqli("127.0.0.1", "root", "", "t1");
mysqli_query($m, "drop table if exists t1.cache");
mysqli_query($m, "create table t1.cache (id int primary key, data mediumtext) engine=myisam");
foreach ($data as $key=>$val) $t[] = mysql_cache($m, $key, $val);
echo stats($t);

echo "mysql memory\n".'<hr>';
$t = array();
mysqli_query($m, "drop table if exists t1.cache");
mysqli_query($m, "create table t1.cache (id int primary key, data varchar(65500)) engine=memory");
foreach ($data as $key=>$val) $t[] = mysql_cache($m, $key, $val);
echo stats($t);

echo "file cache\n".'<hr>';
$t = array();
foreach ($data as $key=>$val) $t[] = file_cache((string)$key, $val);
echo stats($t);

echo "php file cache\n".'<hr>';
$t = array();
foreach ($data as $key=>$val) $t[] = php_cache((string)$key, $val);
echo stats($t);

function stats($t) {
  return "\nTotal: ".number_format(array_sum($t), 3).", ".
    "Avg: ".number_format(array_sum($t) / count($t), 3)."\n\n";
}

function format($num) {
  return number_format($num, 3);
}

function shm($id, $data) {
  if (is_array($data)) {
    $arr = true;
    $data = serialize($data);
  } else $arr = false;
  $len = strlen($data);
  $shm_id = shmop_open($id, "c", 0644, $len);
  shmop_write($shm_id, $data, 0);
  $start = microtime(true);
  if ($arr) {
    for ($i=0; $i<100000; $i++) $v = unserialize(shmop_read($shm_id, 0, $len));
  } else {
    for ($i=0; $i<100000; $i++) $v = shmop_read($shm_id, 0, $len);
  }
  echo format($end = microtime(true)-$start)." ";
  shmop_close($shm_id);
  assert(substr(is_array($v) ? $v[0] : $v, 0, 10)=="1111111110");
  return $end;
}

function apc($id, $data) {
  apc_store($id, $data);
  $start = microtime(true);
  for ($i=0; $i<100000; $i++) $v = apc_fetch($id);
  echo format($end = microtime(true)-$start)." ";
  assert(substr(is_array($v) ? $v[0] : $v, 0, 10)=="1111111110");
  return $end;
}

function memcache($m, $id, $data) {
  memcache_set($m, $id, $data);
  $start = microtime(true);
  for ($i=0; $i<100000; $i++) $v = memcache_get($m, $id);
  echo format($end = microtime(true)-$start)." ";
  assert(substr(is_array($v) ? $v[0] : $v, 0, 10)=="1111111110");
  return $end;
}

function memcached($m, $id, $data) {
  $m->set($id, $data);
  $start = microtime(true);
  for ($i=0; $i<100000; $i++) $v = $m->get($id);
  echo format($end = microtime(true)-$start)." ";
  assert(substr(is_array($v) ? $v[0] : $v, 0, 10)=="1111111110");
  return $end;
}

function mysql_cache($m, $id, $data) {
  $d = is_array($data) ? serialize($data) : $data;
  mysqli_query($m, "insert into t1.cache values (".$id.", '".$d."')");
  $start = microtime(true);
  if (is_array($data)) {
    for ($i=0; $i<100000; $i++) {
      $v = mysqli_query($m, "SELECT data FROM t1.cache WHERE id=".$id)->fetch_row();
      $v = unserialize($v[0]);
    }
  } else {
    for ($i=0; $i<100000; $i++) {
      $v = mysqli_query($m, "SELECT data FROM t1.cache WHERE id=".$id)->fetch_row();
    }
  }
  echo format($end = microtime(true)-$start)." ";
  assert(substr($v[0], 0, 10)=="1111111110");
  return $end;
}

function file_cache($id, $data) {
  file_put_contents($id, is_array($data) ? serialize($data) : $data);
  $start = microtime(true);
  if (is_array($data)) {
    for ($i=0; $i<100000; $i++) $v = unserialize(file_get_contents($id));
  } else {
    for ($i=0; $i<100000; $i++) $v = file_get_contents($id);
  }
  echo format($end = microtime(true)-$start)." ";
  assert(substr(is_array($v) ? $v[0] : $v, 0, 10)=="1111111110");
  return $end;
}

function php_cache($id, $data) {
  $id .= ".php";
  $data = is_array($data) ? var_export($data, 1) : "'".$data."'";
  file_put_contents($id, "<?php\n\$v=".$data.";");
  touch($id, time()-10); // needed for APC's file update protection
  $start = microtime(true);
  for ($i=0; $i<100000; $i++) include($id);
  echo format($end = microtime(true)-$start)." ";
  assert(substr(is_array($v) ? $v[0] : $v, 0, 10)=="1111111110");
  return $end;
}
