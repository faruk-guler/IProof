<?php

/**
 * Pings a single host and returns true if online, false otherwise.
 */
function pingHost($ip) {
    if (stristr(PHP_OS, 'WIN')) {
        // Windows: -n 1 (1 packet), -w 500 (500ms timeout)
        exec("ping -n 1 -w 500 " . escapeshellarg($ip), $output, $status);
        if ($status !== 0) return false;
        $outStr = implode(' ', $output);
        return (stripos($outStr, 'ttl=') !== false);
    } else {
        // Linux/macOS: -c 1 (1 packet), -W 1 (1s timeout)
        exec("ping -c 1 -W 1 " . escapeshellarg($ip), $output, $status);
        return $status === 0;
    }
}

/**
 * Pings multiple hosts in parallel using proc_open.
 * Batches execution to prevent resource exhaustion.
 */
function pingMultiple($ips, $batchSize = 32) {
    $results = [];
    $chunks = array_chunk($ips, $batchSize);

    foreach ($chunks as $chunk) {
        $processes = [];
        $exitCodes = [];
        foreach ($chunk as $ip) {
            $cmd = stristr(PHP_OS, 'WIN')
                ? "ping -n 1 -w 500 " . escapeshellarg($ip)
                : "ping -c 1 -W 1 " . escapeshellarg($ip);

            // Open process without blocking
            $descriptorspec = [
                0 => ["pipe", "r"], // stdin
                1 => ["pipe", "w"], // stdout
                2 => ["pipe", "w"]  // stderr
            ];
            
            $proc = proc_open($cmd, $descriptorspec, $pipes);
            if (is_resource($proc)) {
                fclose($pipes[0]); // Don't need stdin
                $processes[$ip] = [
                    'proc'  => $proc,
                    'pipes' => $pipes
                ];
            } else {
                $results[$ip] = false;
            }
        }

        // Wait for batch to complete with a safety timeout of 5 seconds
        $running = true;
        $start_wait = microtime(true);
        while ($running) {
            $running = false;
            foreach ($processes as $ip => $procData) {
                if (isset($exitCodes[$ip])) continue;
                $status = proc_get_status($procData['proc']);
                if ($status['running']) {
                    $running = true;
                } else {
                    $exitCodes[$ip] = $status['exitcode'];
                }
            }
            if (microtime(true) - $start_wait > 5) {
                // Force close all running processes in the batch
                foreach ($processes as $ip => $procData) {
                    if (isset($exitCodes[$ip])) continue;
                    $status = proc_get_status($procData['proc']);
                    if ($status['running']) {
                        proc_terminate($procData['proc']);
                    }
                    $exitCodes[$ip] = $status['exitcode'];
                }
                $running = false;
                break;
            }
            if ($running) {
                usleep(25000); // Wait 25ms before checking again
            }
        }

        // Collect exit codes & cleanup
        foreach ($processes as $ip => $procData) {
            $stdout = stream_get_contents($procData['pipes'][1]);
            fclose($procData['pipes'][1]);
            fclose($procData['pipes'][2]);

            if (!isset($exitCodes[$ip])) {
                $status = proc_get_status($procData['proc']);
                $exitCodes[$ip] = $status['exitcode'];
            }
            proc_close($procData['proc']);

            if (stristr(PHP_OS, 'WIN')) {
                $results[$ip] = ($exitCodes[$ip] === 0 && stripos($stdout, 'ttl=') !== false);
            } else {
                $results[$ip] = ($exitCodes[$ip] === 0);
            }
        }
    }

    return $results;
}

/**
 * Calculates all host IPs in a given IPv4 subnet/mask.
 */
function getIpRange($subnet, $mask) {
    $mask = (int)$mask;
    if ($mask < 1 || $mask > 32) return [];

    $ip_dec = ip2long($subnet);
    if ($ip_dec === false) return [];

    $host_bits = 32 - $mask;
    $wildcard = ($host_bits === 32) ? 0xFFFFFFFF : ((1 << $host_bits) - 1);
    $net_mask = ($host_bits === 32) ? 0 : (~$wildcard & 0xFFFFFFFF);

    $ip_unsigned = (int)sprintf('%u', $ip_dec);
    $net_ip = $ip_unsigned & $net_mask;

    if ($mask >= 31) {
        $start_ip = $net_ip;
        $end_ip = $net_ip + $wildcard;
    } else {
        $start_ip = $net_ip + 1;
        $end_ip = $net_ip + $wildcard - 1;
    }

    // Guard against huge ranges (e.g. /16 or larger) to prevent memory crash
    if (($end_ip - $start_ip) > 4096) {
        $end_ip = $start_ip + 4096;
    }

    $ips = [];
    for ($i = $start_ip; $i <= $end_ip; $i++) {
        $ips[] = long2ip($i);
    }
    return $ips;
}

/**
 * Scans multiple ports for a list of IPs in parallel using non-blocking stream_socket_client.
 * This is extremely fast because it uses asynchronous socket connections.
 */
function scanPortsMultiple($ips, $ports, $timeout_ms = 100) {
    $results = [];
    $timeout_seconds = $timeout_ms / 1000.0;
    
    foreach ($ips as $ip) {
        $results[$ip] = [];
    }
    
    if (empty($ips) || empty($ports)) {
        return $results;
    }
    
    // We will batch the connections in groups of 64 sockets to prevent resource constraints
    $all_targets = [];
    foreach ($ips as $ip) {
        foreach ($ports as $port) {
            $all_targets[] = ['ip' => $ip, 'port' => $port];
        }
    }
    
    $batches = array_chunk($all_targets, 64);
    
    foreach ($batches as $batch) {
        $sockets = [];
        foreach ($batch as $target) {
            $ip = $target['ip'];
            $port = $target['port'];
            $address = "tcp://{$ip}:{$port}";
            $errno = 0;
            $errstr = '';
            
            // Open non-blocking socket
            $socket = @stream_socket_client($address, $errno, $errstr, $timeout_seconds, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($socket) {
                stream_set_blocking($socket, false);
                $sockets[(int)$socket] = [
                    'socket' => $socket,
                    'ip' => $ip,
                    'port' => $port,
                    'start_time' => microtime(true)
                ];
            }
        }
        
        $start_wait = microtime(true);
        while (count($sockets) > 0 && (microtime(true) - $start_wait) < ($timeout_seconds + 0.1)) {
            $read = null;
            $write = [];
            $except = null;
            
            foreach ($sockets as $sData) {
                $write[] = $sData['socket'];
            }
            
            // Check socket status
            $ready = @stream_select($read, $write, $except, 0, 20000);
            if ($ready > 0 && is_array($write)) {
                foreach ($write as $wSocket) {
                    $id = (int)$wSocket;
                    if (isset($sockets[$id])) {
                        $sData = $sockets[$id];
                        // If it is writable and doesn't have an error, it is open
                        if (@stream_socket_get_name($sData['socket'], true)) {
                            $results[$sData['ip']][] = $sData['port'];
                        }
                        @fclose($sData['socket']);
                        unset($sockets[$id]);
                    }
                }
            }
            
            // Handle timeouts
            foreach ($sockets as $id => $sData) {
                if (microtime(true) - $sData['start_time'] > $timeout_seconds) {
                    @fclose($sData['socket']);
                    unset($sockets[$id]);
                }
            }
            
            usleep(2000); // 2ms sleep to avoid CPU pinning
        }
        
        // Clean up remaining
        foreach ($sockets as $sData) {
            @fclose($sData['socket']);
        }
    }
    
    return $results;
}

/**
 * Multi-layer hostname discovery (NetBIOS, Local Resolver, SNMP sysName, HTTP Title).
 */
function getDeviceHostname($ip, $community = 'public') {
    if (empty($ip) || ip2long($ip) === false) return '';

    // Fast-path: Skip slow hostname discovery for Public Internet IPs to prevent massive scanner freezing
    $is_private = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if (!$is_private) return '';

    // 1. Try Linux mDNS (UDP port 5353 - Avahi / systemd-resolved / Linux shell hostname)
    $parts = explode('.', $ip);
    $revIp = implode('.', array_reverse($parts)) . '.in-addr.arpa';
    $packet = "\x00\x01\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00";
    foreach (explode('.', $revIp) as $label) {
        $packet .= chr(strlen($label)) . $label;
    }
    $packet .= "\x00\x00\x0c\x00\x01"; // TYPE PTR, CLASS IN

    $fp = @fsockopen("udp://$ip", 5353, $errno, $errstr, 0.1);
    if ($fp) {
        stream_set_timeout($fp, 0, 100000); // 100ms timeout
        @fwrite($fp, $packet);
        $res = @fread($fp, 512);
        @fclose($fp);
        if ($res && strlen($res) > 12) {
            $pos = strpos($res, "\x00\x0c\x00\x01");
            if ($pos !== false && strlen($res) > ($pos + 10)) {
                $rdLength = (ord($res[$pos + 8]) << 8) + ord($res[$pos + 9]);
                $rDataPos = $pos + 10;
                if (strlen($res) >= ($rDataPos + $rdLength)) {
                    $ptrName = '';
                    $p = $rDataPos;
                    while ($p < ($rDataPos + $rdLength) && ord($res[$p]) > 0) {
                        $len = ord($res[$p]);
                        if (($len & 0xC0) === 0xC0) break;
                        if ($len > 0 && ($p + 1 + $len) <= strlen($res)) {
                            $ptrName .= substr($res, $p + 1, $len) . '.';
                            $p += (1 + $len);
                        } else break;
                    }
                    $ptrName = rtrim($ptrName, '.');
                    $cleanHost = preg_replace('/\.(local|localdomain|lan|home|internal)$/i', '', $ptrName);
                    if (!empty($cleanHost) && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $cleanHost) && $cleanHost !== $ip) {
                        return $cleanHost;
                    }
                }
            }
        }
    }

    // 2. Try NetBIOS Name Query (UDP port 137 - Windows / Samba / NAS)
    $fp = @fsockopen("udp://$ip", 137, $errno, $errstr, 0.1);
    if ($fp) {
        stream_set_timeout($fp, 0, 100000); // 100ms timeout
        $packet = "\x80\x94\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00\x20\x43\x4b\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x41\x00\x00\x21\x00\x01";
        @fwrite($fp, $packet);
        $res = @fread($fp, 560);
        @fclose($fp);
        if ($res && strlen($res) > 56) {
            $num_names = ord($res[56]);
            if ($num_names > 0 && strlen($res) >= (57 + 18 * $num_names)) {
                $name = trim(substr($res, 57, 15));
                if (!empty($name) && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $name)) {
                    return $name;
                }
            }
        }
    }

    // 3. Try System Local Resolver (gethostbyaddr / local hosts)
    $dns_host = @gethostbyaddr($ip);
    if (!empty($dns_host) && $dns_host !== $ip) {
        $clean = preg_replace('/\.(local|localdomain|lan|home|internal)$/i', '', $dns_host);
        return $clean;
    }

    // 4. Try SNMP sysName (.1.3.6.1.2.1.1.5.0)
    if (function_exists('snmp2_get') || function_exists('snmpget')) {
        @snmp_set_quick_print(true);
        $sysName = @snmp2_get($ip, $community, "1.3.6.1.2.1.1.5.0", 200000);
        if ($sysName === false) {
            $sysName = @snmpget($ip, $community, "1.3.6.1.2.1.1.5.0", 200000);
        }
        if ($sysName !== false) {
            $name = trim(str_replace(['"', 'STRING:', 'Hex-STRING:'], '', $sysName));
            if (!empty($name) && $name !== $ip) {
                return preg_replace('/\.(local|localdomain|lan|home|internal)$/i', '', $name);
            }
        }
    }

    return '';
}
