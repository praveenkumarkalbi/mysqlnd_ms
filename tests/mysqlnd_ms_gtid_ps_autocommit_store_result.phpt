--TEST--
PS, autocommit, GTID, stmt.store_result
--SKIPIF--
<?php
require_once('skipif.inc');
require_once("connect.inc");

if (version_compare(PHP_VERSION, '5.3.99-dev', '<'))
	die(sprintf("SKIP Requires PHP >= 5.3.99, using " . PHP_VERSION));

_skipif_check_extensions(array("mysqli"));
_skipif_connect($emulated_master_host_only, $user, $passwd, $db, $emulated_master_port, $emulated_master_socket);
_skipif_connect($emulated_slave_host_only, $user, $passwd, $db, $emulated_slave_port, $emulated_slave_socket);

if (($emulated_master_host == $emulated_slave_host)) {
	die("SKIP master and slave seem to the the same, see tests/README");
}

include_once("util.inc");
$sql = mst_get_gtid_sql($db);
if ($error = mst_mysqli_setup_gtid_table($emulated_master_host_only, $user, $passwd, $db, $emulated_master_port, $emulated_master_socket))
  die(sprintf("SKIP Failed to setup GTID on master, %s\n", $error));
if ($error = mst_mysqli_setup_gtid_table($emulated_slave_host_only, $user, $passwd, $db, $emulated_slave_port, $emulated_slave_socket))
  die(sprintf("SKIP Failed to setup GTID on slave, %s\n", $error));

$settings = array(
	"myapp" => array(
		'master' => array($emulated_master_host),
		'slave' => array($emulated_slave_host),
		'global_transaction_id_injection' => array(
		 	'type'						=> 1,
			'on_commit'	 				=> $sql['update'],
			'fetch_last_gtid'			=> $sql['fetch_last_gtid'],
			'report_error'				=> true,
		),

		'lazy_connections' => 1,
		'trx_stickiness' => 'disabled',
		'filters' => array(
			"quality_of_service" => array(
				"session_consistency" => 1,
			),
			"roundrobin" => array(),
		),
	),
);
if ($error = mst_create_config("test_mysqlnd_ms_gtid_ps_autocommit_store_result.ini", $settings))
	die(sprintf("SKIP %s\n", $error));
?>
--INI--
mysqlnd_ms.enable=1
mysqlnd_ms.config_file=test_mysqlnd_ms_gtid_ps_autocommit_store_result.ini
mysqlnd_ms.collect_statistics=1
--FILE--
<?php
	require_once("connect.inc");
	require_once("util.inc");

	function compare_stats($offset, $stats, $expected) {
		foreach ($stats as $name => $value) {
			if (isset($expected[$name])) {
				if ($value != $expected[$name]) {
					printf("[%03d] Expecting %s = %d got %d\n", $offset, $name, $expected[$name], $value);
				}
				unset($expected[$name]);
			}
		}
		if (!empty($expected)) {
			printf("[%03d] Dumping list of missing stats\n", $offset);
			var_dump($expected);
		}
	}

	if (!($link = mst_mysqli_connect("myapp", $user, $passwd, $db, $port, $socket)))
		printf("[001] [%d] %s\n", mysqli_connect_errno(), mysqli_connect_error());

	  $expected = array(
		"gtid_autocommit_injections_success" => 0,
		"gtid_autocommit_injections_failure" => 0,
		"gtid_commit_injections_success" => 0,
		"gtid_commit_injections_failure" => 0,
	);

	if (!$link->query("DROP TABLE IF EXISTS test") ||
		!$link->query("CREATE TABLE test(id INT) ENGINE=InnoDB"))
		printf("[002] [%d] %s\n", $link->errno, $link->error);

	$expected['gtid_autocommit_injections_success'] += 2;

	/* statement created in autocommit mode on master connection */
	if (!($stmt = $link->prepare(sprintf("/*%s*/SELECT COUNT(*) AS _num_rows FROM test", MYSQLND_MS_MASTER_SWITCH))))
		printf("[003] [%d] %s\n", $link->errno, $link->error);

	$stats = mysqlnd_ms_get_stats();
	compare_stats(4, $stats, $expected);

	if (!$stmt->execute())
		printf("[005] [%d] %s\n", $stmt->errno, $stmt->error);

	$expected['gtid_autocommit_injections_success']++;
	$stats = mysqlnd_ms_get_stats();
	compare_stats(6, $stats, $expected);

	$num_rows = NULL;
	if (!$stmt->bind_result($num_rows))
		printf("[007] [%d] %s\n", $stmt->errno, $stmt->error);

	if (!($res = $stmt->store_result()))
		printf("[008] [%d] %s\n", $stmt->errno, $stmt->error);

	if (!($res = $stmt->fetch()))
		printf("[009] [%d] %s\n", $stmt->errno, $stmt->error);

	printf("Rows %d\n", $num_rows);

	if (!$link->query("INSERT INTO test(id) VALUES (1)"))
		printf("[010] [%d] %s\n", $link->errno, $link->error);

	$expected['gtid_autocommit_injections_success']++;
	$stats = mysqlnd_ms_get_stats();
	compare_stats(11, $stats, $expected);

	if (!$stmt->execute())
		printf("[012] [%d] %s\n", $stmt->errno, $stmt->error);

	$expected['gtid_autocommit_injections_success']++;
	$stats = mysqlnd_ms_get_stats();
	compare_stats(13, $stats, $expected);

	if (!($res = $stmt->store_result()))
		printf("[014] [%d] %s\n", $stmt->errno, $stmt->error);

	if (!($res = $stmt->fetch()))
		printf("[015] [%d] %s\n", $stmt->errno, $stmt->error);

	printf("Rows %d\n", $num_rows);

	if (!$stmt->store_result()) {
		printf("[016] [%d] %s\n", $stmt->errno, $stmt->error);
		printf("[017] [%d] %s\n", $link->errno, $link->error);
	} else {
		if (!($res = $stmt->fetch()))
			printf("[018] [%d] %s\n", $stmt->errno, $stmt->error);

		printf("Rows %d\n", $num_rows);
	}

	if (!$link->query("INSERT INTO test(id) VALUES (1)"))
		printf("[019] [%d] %s\n", $link->errno, $link->error);

	$expected['gtid_autocommit_injections_success']++;
	$stats = mysqlnd_ms_get_stats();
	compare_stats(20, $stats, $expected);

	if (!$stmt->execute())
		printf("[021] [%d] %s\n", $stmt->errno, $stmt->error);

	$expected['gtid_autocommit_injections_success']++;
	$stats = mysqlnd_ms_get_stats();
	compare_stats(22, $stats, $expected);

	if (!($res = $stmt->store_result()))
		printf("[023] [%d] %s\n", $stmt->errno, $stmt->error);

	if (!($res = $stmt->fetch()))
		printf("[024] [%d] %s\n", $stmt->errno, $stmt->error);

	printf("Rows %d\n", $num_rows);

	if (!$stmt->execute())
		printf("[025] [%d] %s\n", $stmt->errno, $stmt->error);

	$expected['gtid_autocommit_injections_success']++;
	$stats = mysqlnd_ms_get_stats();
	compare_stats(26, $stats, $expected);

	if (!$stmt->execute()) { //Why we should receive an out of sync error???
		printf("[027] [%d] %s\n", $stmt->errno, $stmt->error);
		printf("[028] [%d] %s\n", $link->errno, $link->error);
	}

	/* commands out of sync */ //Why we should receive an out of sync error???
	//$expected['gtid_autocommit_injections_failure']++;
	$expected['gtid_autocommit_injections_success']++;
	$stats = mysqlnd_ms_get_stats();
	compare_stats(29, $stats, $expected);

	if (!($res = $stmt->store_result()))
		printf("[030] [%d] %s\n", $stmt->errno, $stmt->error);

	if (!$link->query("DROP TABLE IF EXISTS test"))
		printf("[031] [%d] %s\n", $link->errno, $link->error);

	$expected['gtid_autocommit_injections_success']++;
	$stats = mysqlnd_ms_get_stats();
	compare_stats(32, $stats, $expected);

	if (!$stmt->execute())
		printf("[033] [%d] %s\n", $stmt->errno, $stmt->error);

	$stats = mysqlnd_ms_get_stats();
	compare_stats(34, $stats, $expected);

	print "done!";
?>
--CLEAN--
<?php
	if (!unlink("test_mysqlnd_ms_gtid_ps_autocommit_store_result.ini"))
		printf("[clean] Cannot unlink ini file 'test_mysqlnd_ms_gtid_ps_autocommit_store_result.ini'.\n");

	require_once("connect.inc");
	require_once("util.inc");
	if ($error = mst_mysqli_drop_test_table($emulated_master_host_only, $user, $passwd, $db, $emulated_master_port, $emulated_master_socket))
		printf("[clean] %s\n", $error);

	if ($error = mst_mysqli_drop_gtid_table($emulated_master_host_only, $user, $passwd, $db, $emulated_master_port, $emulated_master_socket))
		printf("[clean] %s\n", $error);
	if ($error = mst_mysqli_drop_gtid_table($emulated_slave_host_only, $user, $passwd, $db, $emulated_slave_port, $emulated_slave_socket))
		printf("[clean] %s\n", $error);
?>
--EXPECTF--
Rows 0
Rows 1
[016] [0%A
[017] [2014] %s
Rows 2
[033] [1146] %s
done!