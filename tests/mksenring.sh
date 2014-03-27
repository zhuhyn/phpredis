#!/bin/bash

# Write sentinel configuration directive for a given master port
function write_conf() {    
    echo "# -- master$1 -- " >> phpredis_sentinel.conf
    echo "sentinel monitor master$1 127.0.0.1 $1 2" >> phpredis_sentinel.conf
    echo "sentinel down-after-milliseconds master$1 60000" >> phpredis_sentinel.conf
    echo "sentinel failover-timeout master$1 1000" >> phpredis_sentinel.conf
    echo "sentinel parallel-syncs master$1 1" >> phpredis_sentinel.conf
    echo "" >> phpredis_sentinel.conf
}

# Spin up some redis-server processes as well as a sentinel
function start() {
    # Remove the original sentinel file (in case redis-sentinel rewrote it)
    if [[ -e "phpredis_sentinel.conf" ]]; then
        rm phpredis_sentinel.conf
    fi

    # Spin some masters
    for P in `seq 7000 7002`; do
        # Spin up a master
        echo "Spinning up a master at port $P"

        redis-server --port $P --save '' --daemonize yes
        if [[ $? -ne 0 ]]; then
            echo "Can't spin master at port $P"
            exit 1
        fi

        # Write config directives for this master
        write_conf $P
    done

    # Spin some slaves
    for P in `seq 7003 7005`; do
        # Master port is slave port - 3
        MP="$(($P-3))"

        # Spin up a corresponding slave
        echo "Spinning up slave of instance $P on port $SP"

        redis-server --port $P --slaveof 127.0.0.1 $MP --save '' --daemonize yes
        if [[ $? -ne 0 ]]; then
            echo "Can't spin slave of port $MP at port $P"
            exit 1
        fi
    done

    # Spin the sentinel
    redis-server phpredis_sentinel.conf --port 26379 --sentinel
}

# Stop master/slave instances and sentinel
function stop() {
    for P in `seq 7000 7005`; do
        # Stop master
        echo "Stopping server at port $P"
        redis-cli -p $P shutdown nosave > /dev/null 2>&1
    done

    # Stop our sentinel
    echo "Stopping sentinel at port 26739"
    redis-cli -p 26379 shutdown nosave > /dev/null 2>&1
}

case "$1" in 
    start)
        start "$PORTS"
        ;;
    stop)
        stop "$PORTS"
        ;;
    restart)
        stop "$PORTS"
        start "$PORTS"
        ;;
    *)
        echo "Usage $0 [start|stop|restart]"
esac
