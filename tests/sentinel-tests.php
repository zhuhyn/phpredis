<?php
require_once(dirname($_SERVER['PHP_SELF'])."/test.php");

echo "Redis Sentinel command tests.\n\n";
echo "Make sure to run mksenring.sh to get a sentinel up monitoring a few redis-server\n";
echo "masters with slaves.  Otherwise you could manually set up a sentinel config.\n\n";

class Redis_Sentinel_Test extends TestSuite {
    private $redis;
    private $masters = Array();

    public function setUp() {
        // Connect to our sentinel instance
        try {
            $this->redis = new Redis();
            $this->redis->connect('127.0.0.1', 26379);
        } catch(Exception $ex) {
            echo "Error:  Can't get a connection to sentinel on port 26379\n";
            exit(1);
        }

        // Grab our masters
        foreach($this->redis->sentinel('masters') as $master) {
            $this->masters[] = $master['name'];
        }

        // Make sure something is up and configured
        if(!$this->masters) {
            echo "Error:  No masters detected, can't run tests!\n";
            exit(1);
        }
    }

    // PING but to our sentinel instance
    public function testPing() {
        $this->assertTrue($this->redis->ping());
    }

    // SENTINEL MASTERS
    public function testMasters() {
        $masters = $this->redis->sentinel('masters');

        $this->assertTrue(is_array($masters));
        $this->assertTrue(is_array($masters[0]));

        // Check a few fields
        $master1 = $masters[0];
        $this->assertTrue(isset($master1['name']));
        $this->assertTrue(isset($master1['runid']));
        $this->assertTrue(isset($master1['num-slaves']));
    }

    // SENTINEL MASTER
    public function testMaster() {
        foreach($this->masters as $master) {
            $info = $this->redis->sentinel('master', $master);
            $this->assertTrue(is_array($info));

            $this->assertTrue(isset($info['name']));
            $this->assertTrue(isset($info['ip']));
            $this->assertTrue(isset($info['port']));
        }
    }

    // SENTINEL SLAVES
    public function testSlaves() {
        foreach($this->masters as $master) {
            $info = $this->redis->sentinel('master', $master);
            if($info['num-slaves']>0) {
                $slaves = $this->redis->sentinel('slaves', $master);
                $this->assertTrue(is_array($slaves));

                foreach($slaves as $slave) {
                    $this->assertTrue(isset($slave['name']));
                    $this->assertTrue(isset($slave['ip']));
                    $this->assertTrue(isset($slave['port']));
                }
            }
        }
    }

    // SENTINEL get-master-addr-by-name
    public function testGetMasterAddr() {
        foreach($this->masters as $master) {
            $info = $this->redis->sentinel('get-master-addr-by-name', $master);
            $this->assertTrue(is_array($info));
            $this->assertEquals(count($info), 2);
        }
    }

    // SENTINEL RESET   
    public function testReset() {
        $resp = $this->redis->sentinel('reset', '*');
        $this->assertEquals($resp, count($this->masters));
    }

    // SENTINEL REMOVE / MONITOR 
    public function testRemoveMonitor() {
        // Pick one at random, grab info, and remove it
        $master = $this->masters[array_rand($this->masters)];
        $info = $this->redis->sentinel('master', $master);
        $this->assertTrue($this->redis->sentinel('remove', $master));

        // Now attempt to re-add it
        $resp = $this->redis->sentinel('monitor', $info['name'], $info['ip'], $info['port'], $info['quorum']);
        $this->assertTrue($resp);
    }

    // SENTINEL SET
    public function testSet() {
        $master = $this->masters[array_rand($this->masters)];
        $this->assertTrue($this->redis->sentinel('set', $master, 'quorum', 4));
    }

    // TEST FAILOVER
    public function testFailover() {
        foreach($this->masters as $master) {
            // We've been resetting the sentinel and it might not be ready yet
            while(true) {
                $info = $this->redis->sentinel('master', $master);
                if($info['num-slaves'] > 0) break;
                sleep(1);
            }

            if($info['num-slaves']>0) {
                $resp = $this->redis->sentinel('failover', $master);
                $this->assertTrue($resp);
            }
        }
    }

    // INFO (sentenel specific fields)
    public function testInfo() {
        $info = $this->redis->info();
        $this->assertTrue(isset($info['sentinel_masters']));
        $this->assertTrue(isset($info['sentinel_tilt']));
    }
}

// Run the tests
TestSuite::Run('Redis_Sentinel_Test');

?>
