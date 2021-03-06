<?php
/* vim:set softtabstop=4 shiftwidth=4 expandtab: */
/**
 *
 * LICENSE: GNU General Public License, version 2 (GPLv2)
 * Copyright 2001 - 2013 Ampache.org
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License v2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

/**
 * Access Class
 *
 * This class handles the access list mojo for Ampache, it is meant to restrict
 * access based on IP and maybe something else in the future.
 *
 */
class Access
{
    // Variables from DB
    public $id;
    public $name;
    public $start;
    public $end;
    public $level;
    public $user;
    public $type;
    public $enabled;

    /**
     * constructor
     *
     * Takes an ID of the access_id dealie :)
     */
    public function __construct($access_id = '')
    {
        if (!$access_id) { return false; }

        /* Assign id for use in get_info() */
        $this->id = intval($access_id);

        $info = $this->_get_info();
        foreach ($info as $key=>$value) {
            $this->$key = $value;
        }

        return true;
    }

    /**
     * _get_info
     *
     * Gets the vars for $this out of the database.
     */
    private function _get_info()
    {
        $sql = 'SELECT * FROM `access_list` WHERE `id` = ?';
        $db_results = Dba::read($sql, array($this->id));

        $results = Dba::fetch_assoc($db_results);

        return $results;
    }

    /**
     * format
     *
     * This makes the Access object a nice fuzzy human readable object, spiffy
     * ain't it.
     */
    public function format()
    {
        $this->f_start = inet_ntop($this->start);
        $this->f_end = inet_ntop($this->end);

        $this->f_user = $this->get_user_name();
        $this->f_level = $this->get_level_name();
        $this->f_type = $this->get_type_name();
    }

    /**
     * _verify_range
     *
     * This outputs an error if the IP range is bad.
     */
    private static function _verify_range($startp, $endp)
    {
        $startn = @inet_pton($startp);
        $endn = @inet_pton($endp);

        if (!$startn && $startp != '0.0.0.0' && $startp != '::') {
            Error::add('start', T_('Invalid IPv4 / IPv6 Address Entered'));
            return false;
        }
        if (!$endn) {
            Error::add('end', T_('Invalid IPv4 / IPv6 Address Entered'));
        }

        if (strlen(bin2hex($startn)) != strlen(bin2hex($endn))) {
            Error::add('start', T_('IP Address Version Mismatch'));
            Error::add('end', T_('IP Address Version Mismatch'));
            return false;
        }

        return true;
    }

    /**
     * update
     *
     * This function takes a named array as a datasource and updates the current
     * access list entry.
     */
    public function update($data)
    {
        if (!self::_verify_range($data['start'], $data['end'])) {
            return false;
        }

        $start = @inet_pton($data['start']);
        $end = @inet_pton($data['end']);
        $name = $data['name'];
        $type = self::validate_type($data['type']);
        $level = intval($data['level']);
        $user = $data['user'] ?: '-1';
        $enabled = make_bool($data['enabled']) ? 1 : 0;

        $sql = 'UPDATE `access_list` SET `start` = ?, `end` = ?, `level` = ?, ' .
            '`user` = ?, `name` = ?, `type` = ?, `enabled` = ? WHERE `id` = ?';
        $db_results = Dba::write($sql,
            array($start, $end, $level, $user, $name, $type, $enabled, $this->id));

        return true;
    }

    /**
     * create
     *
     * This takes a keyed array of data and trys to insert it as a
     * new ACL entry
     */
    public static function create($data)
    {
        if (!self::_verify_range($data['start'], $data['end'])) {
            return false;
        }

        // Check existing ACLs to make sure we're not duplicating values here
        if (self::exists($data)) {
            debug_event('ACL Create', 'Error: An ACL equal to the created one already exists. Not adding another one: ' . $data['start'] . ' - ' . $data['end'], 1);
            Error::add('general', T_('Duplicate ACL defined'));
            return false;
        }

        $start = @inet_pton($data['start']);
        $end = @inet_pton($data['end']);
        $name = $data['name'];
        $user = $data['user'] ?: '-1';
        $level = intval($data['level']);
        $type = self::validate_type($data['type']);
        $enabled = make_bool($data['enabled']) ? 1 : 0;

        $sql = 'INSERT INTO `access_list` (`name`, `level`, `start`, `end`, ' .
            '`user`,`type`,`enabled`) VALUES (?, ?, ?, ?, ?, ?, ?)';
        $db_results = Dba::write($sql, array($name, $level, $start, $end, $user, $type, $enabled));

        return true;

    }

    /**
     * exists
     *
     * This sees if the ACL that we've specified already exists in order to
     * prevent duplicates. The name is ignored.
     */
    public static function exists($data)
    {
        $start = inet_pton($data['start']);
        $end = inet_pton($data['end']);
        $type = self::validate_type($data['type']);
        $user = $data['user'] ?: '-1';

        $sql = 'SELECT * FROM `access_list` WHERE `start` = ? AND `end` = ? ' .
            'AND `type` = ? AND `user` = ?';
        $db_results = Dba::read($sql, array($start, $end, $type, $user));

        if (Dba::fetch_assoc($db_results)) {
            return true;
        }

        return false;
    }

    /**
     * delete
     *
     * deletes the specified access_list entry
     */
    public static function delete($id)
    {
        Dba::write('DELETE FROM `access_list` WHERE `id` = ?', array($id));
    }

    /**
     * check_function
     *
     * This checks if specific functionality is enabled.
     */
    public static function check_function($type)
    {
        switch ($type) {
            case 'download':
                return AmpConfig::get('download');
            break ;
            case 'batch_download':
                if (!function_exists('gzcompress')) {
                    debug_event('access', 'ZLIB extension not loaded, batch download disabled', 3);
                    return false;
                }
                if (AmpConfig::get('allow_zip_download') AND $GLOBALS['user']->has_access('25')) {
                    return AmpConfig::get('download');
                }
            break;
            default:
                return false;
            break;
        }
    }

    /**
     * check_network
     *
     * This takes a type, ip, user, level and key and then returns whether they
     * are allowed. The IP is passed as a dotted quad.
     */
    public static function check_network($type, $user, $level, $ip=null)
    {
        if (!AmpConfig::get('access_control')) {
            switch ($type) {
                case 'interface':
                case 'stream':
                    return true;
                break;
                default:
                    return false;
            }
        }

        // Clean incoming variables
        $ip = $ip ?: $_SERVER['REMOTE_ADDR'];
        $ip = inet_pton($ip);

        switch ($type) {
            case 'init-api':
                if ($user) {
                    $user = User::get_from_username($user);
                    $user = $user->id;
                }
            case 'api':
                $type = 'rpc';
            case 'network':
            case 'interface':
            case 'stream':
            break;
            default:
                return false;
            break;
        } // end switch on type

        $sql = 'SELECT `id` FROM `access_list` ' .
            'WHERE `start` <= ? AND `end` >= ? ' .
            'AND `level` >= ? AND `type` = ?';

        $params = array($ip, $ip, $level, $type);

        if (strlen($user) && $user != '-1') {
            $sql .= " AND `user` IN(?, '-1')";
            $params[] = $user;
        } else {
            $sql .= " AND `user` = '-1'";
        }

        $db_results = Dba::read($sql, $params);

        if (Dba::fetch_row($db_results)) {
            // Yah they have access they can use the mojo
            return true;
        }

        return false;
    }

    /**
     * check_access
     *
     * This is the global 'has_access' function.(t can check for any 'type'
     * of object.
     *
     * Everything uses the global 0,5,25,50,75,100 stuff. GLOBALS['user'] is
     * always used.
     */
    public static function check($type, $level)
    {
        if (AmpConfig::get('demo_mode')) {
            return true;
        }
        if (defined('INSTALL')) {
            return true;
        }

        $level = intval($level);

        // Switch on the type
        switch ($type) {
            case 'localplay':
                // Check their localplay_level
                if (AmpConfig::get('localplay_level') >= $level
                    || $GLOBALS['user']->access >= 100) {
                    return true;
                } else {
                    return false;
                }
            break;
            case 'interface':
                // Check their standard user level
                if ($GLOBALS['user']->access >= $level) {
                    return true;
                } else {
                    return false;
                }
            break;
            default:
                return false;
            break;
        }

        return false;
    }

    /**
     * validate_type
     *
     * This validates the specified type; it will always return a valid type,
     * even if you pass in an invalid one.
     */
    public static function validate_type($type)
    {
        switch ($type) {
            case 'rpc':
            case 'interface':
            case 'network':
                return $type;
            break;
            default:
                return 'stream';
            break;
        }
    }

    /**
     * get_access_lists
     * returns a full listing of all access rules on this server
     */
    public static function get_access_lists()
    {
        $sql = 'SELECT `id` FROM `access_list`';
        $db_results = Dba::read($sql);

        $results = array();

        while ($row = Dba::fetch_assoc($db_results)) {
            $results[] = $row['id'];
        }

        return $results;
    }


    /**
     * get_level_name
     *
     * take the int level and return a named level
     */
    public function get_level_name()
    {
        if ($this->level >= '75') {
            return T_('All');
        }
        if ($this->level == '5') {
            return T_('View');
        }
        if ($this->level == '25') {
            return T_('Read');
        }
        if ($this->level == '50') {
            return T_('Read/Write');
        }
    }

    /**
     * get_user_name
     *
     * Return a name for the users covered by this ACL.
     */
    public function get_user_name()
    {
        if ($this->user == '-1') { return T_('All'); }

        $user = new User($this->user);
        return $user->fullname . " (" . $user->username . ")";
    }

    /**
     * get_type_name
     *
     * This function returns the pretty name for our current type.
     */
    public function get_type_name()
    {
        switch ($this->type) {
            case 'rpc':
                return T_('API/RPC');
            break;
            case 'network':
                return T_('Local Network Definition');
            break;
            case 'interface':
                return T_('Web Interface');
            break;
            case 'stream':
            default:
                return T_('Stream Access');
            break;
        }
    }
}
