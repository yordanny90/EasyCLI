<?php

/**
 * @class EasyCLI
 */
class EasyCLI{
	protected $cmd;
	protected $cwd;
	protected $env;
	protected $options;

    /**
     * Detecta si se puede utilizar un array como comando
     * @return bool
     * @see EasyCLI::set_cmd()
     */
    public static function use_command_array(){
        return PHP_VERSION_ID>=70400;
    }

	public function __construct($cmd, $cwd=null, array $env=[], ?array $options=null){
		$this->set_cmd($cmd);
		$this->set_cwd($cwd);
		$this->set_env($env);
		$this->set_options($options);
	}

	public static function newCleanEnv($cmd, ?string $cwd=null){
		return new static($cmd, $cwd??getcwd(), self::get_clean_env());
	}


    public function get_cmd(){
		return $this->cmd;
	}

	public function get_cwd(){
		return $this->cwd;
	}

	public function get_env(){
		return $this->env;
	}

	public function get_options(){
		return $this->options;
	}

    /**
     * Si {@see EasyCLI::use_command_array()} es FALSE, automáticamente se escapan los argumentos mediante {@see EasyCLI::command_args()}
     * @param string|array $cmd
     * @return void
     */
	public function set_cmd($cmd){
        if(is_array($cmd)){
            $cmd=array_values($cmd);
            if(!static::use_command_array()){
                $cmd=static::command_args(...$cmd);
            }
        }
        elseif(!is_string($cmd)){
            $cmd=strval($cmd);
        }
		$this->cmd=$cmd;
	}

	public function set_cwd($cwd){
		$this->cwd=$cwd;
	}

	public function set_env(array $env){
		if(!isset($env['PATH']) && isset($_SERVER['PATH'])) $env['PATH']=$_SERVER['PATH'];
		$this->env=$env;
	}

	public function set_options(?array $options){
		$this->options=$options;
	}

	/**
     * ## Cuidado al usar "pipe": Porque el hijo puede quedar bloqueado si intenta escribir más de ~64 KB y nadie lee
     * @param null|string|resource|array $outBuffer Dirección del archivo de salida (borra el contenido del archivo).<br>
     * Si es "temp" o "tmp", crea un recurso temporal. No se puede usar {@see \EasyCLI\Proc::getOutfile()}<br>
	 * Si es "pipe", crea una tubería sin archivo. No se puede usar {@see \EasyCLI\Proc::getOutfile()}<br>
     * Si es "output", se transfiere directo a la salida del proceso actual<br>
     * Si es un recurso, crea una tubería que escribe en ese recurso. No se puede usar {@see \EasyCLI\Proc::getOutfile()}<br>
     * Si es un array, lo usa como $descriptor_spec para {@see proc_open()}, ejemplo: ["file", "php://temp", "r"]<br>
     * "nul", "/dev/null" o cualquier otro dato, no se crea la tubería
     * @param null|string|resource|array $errBuffer Dirección del archivo de errores (borra el contenido del archivo).<br>
     * Si es "temp" o "tmp", crea un recurso temporal. No se puede usar {@see \EasyCLI\Proc::getErrfile()}<br>
	 * Si es "pipe", crea una tubería sin archivo. No se puede usar {@see \EasyCLI\Proc::getErrfile()}<br>
     * Si es "output", se transfiere directo a la salida del proceso actual<br>
     * Si es un recurso, crea una tubería que escribe en ese recurso. No se puede usar {@see \EasyCLI\Proc::getErrfile()}<br>
     * Si es un array, lo usa como $descriptor_spec para {@see proc_open()}, ejemplo: ["file", "php://temp", "r"]<br>
     * "nul", "/dev/null" o cualquier otro dato, no se crea la tubería
     * @param bool $inBuffer Si es TRUE, se crea el pipe de entrada para el nuevo proceso
     * @return \EasyCLI\Proc|null
     */
	public function open($outBuffer=null, $errBuffer=null, bool $inBuffer=false){
		$desc_spec=[];
		$desc_spec[0]=$inBuffer?["pipe", "r"]:['file', self::fileNULL(), 'r'];
        $desc_spec[1]=self::toPipe($outBuffer, $outClose);
        $desc_spec[2]=self::toPipe($errBuffer, $errClose);
        if($desc_spec[1]===null) unset($desc_spec[1]);
        if($desc_spec[2]===null) unset($desc_spec[2]);
		$proc=\EasyCLI\Proc::start($this->cmd, $desc_spec, $this->cwd, $this->env, $this->options);
        if($proc){
            if(!$inBuffer) $proc->in_close();
            if($outClose) $proc->out_unset();
            if($errClose) $proc->err_unset();
        }
		return $proc;
	}

    /**
     * @var int Memoria máxima usada por los archivos temporales
     * Default: 65536 (64KB)
     */
    public static $maxMemory=65536;

    private static function toPipe($desc_spec, &$isNull=null){
        $isNull=false;
        if(is_array($desc_spec)){
            return $desc_spec;
        }
        elseif(is_resource($desc_spec)){
            return $desc_spec;
        }
        elseif($desc_spec==='temp' || $desc_spec==='tmp'){
            return fopen('php://temp/maxmemory:'.self::$maxMemory, 'r')?:null;
        }
        elseif($desc_spec==='output'){
            return null;
        }
        elseif($desc_spec==='pipe'){
            return ["pipe", "w"];
        }
        elseif(is_string($desc_spec) && !in_array(strtolower($desc_spec), [
                'nul',
                '/dev/null'
            ])){
            return ["file", $desc_spec, "w"];
        }
        $isNull=true;
        return ["file", self::fileNULL(), "w"];
    }

    private static $filenul;

    public static function fileNULL(){
        if(isset(self::$filenul)) return self::$filenul;
        if(strncasecmp(PHP_OS_FAMILY, 'Windows', 7) === 0){
            self::$filenul="NUL";
        }
        else{
            self::$filenul="/dev/null";
        }
        // Valida si tiene permisos para la lectura/escritura de /dev/null
        if(!fopen(self::$filenul, 'r')){
            self::$filenul='php://temp/maxmemory:'.self::$maxMemory;
        }
        return self::$filenul;
    }

    public static function command_args($command, ...$args){
        return escapeshellcmd($command).static::args(...$args);
    }

	public static function args(...$args){
		$res='';
		foreach($args AS &$arg){
			$res.=' '.escapeshellarg($arg);
		}
		return $res;
	}

    private static $clean_env;

    /**
     * @return array
     */
	public static function get_clean_env(){
		if(is_array(self::$clean_env)) return self::$clean_env;
		$env=[];
		foreach(getenv() as $k=>$v){
			if(!in_array($k, [
					'argc',
					'argv',
					'DOCUMENT_URI',
					'SERVER_SOFTWARE',
					'HTTPS',
					'DOCUMENT_ROOT',
					'QUERY_STRING',
					'SERVER_PROTOCOL',
					'SESSION_MANAGER',
					'DISPLAY',
					'WAYLAND_DISPLAY',
					'LOGNAME',
					'USER',
					'LANG',
					'HOME',
				]) && !preg_match('/^(REQUEST_|HTTP_|SCRIPT_|PATH_|PHP_|ORIG_|REDIRECT_|GATEWAY_|CONTEXT_|CONTENT_|FCGI_|XDEBUG_|XDG_|DBUS_|GNOME_|KDE_|QT_|SSH_|LC_)/', $k)){
				if(is_string($v2=getenv($k))){
					$env[$k]=$v2;
				}
			}
		}
		self::$clean_env=$env;
		return $env;
	}

    private static $os_type;

    /**
     * Obtiene la familia del sistema operativo actual
     * @return string|void Posibles valores: "win", "linux", "bsd", "mac", "other"
     */
    public static function getOSType(){
        if(self::$os_type) return self::$os_type;
        $os=strtolower(php_uname('s'));
        if(strstr($os, 'windows')!==false) return self::$os_type='win';
        if(strstr($os, 'linux')!==false) return self::$os_type='linux';
        if(strstr($os, 'bsd')!==false) return self::$os_type='bsd';
        if(strstr($os, 'mac')!==false) return self::$os_type='mac';
        return self::$os_type='other';
    }

    private static $exists=[];

    public static function exist_wmic(): bool{
        if(is_bool(self::$exists['wmic']??null)) return self::$exists['wmic'];
        exec('wmic /?', $o, $res);
        return self::$exists['wmic']=($res===0);
    }

    public static function exist_powershell(): bool{
        if(is_bool(self::$exists['ps']??null)) return self::$exists['ps'];
        exec('powershell /?', $o, $res);
        return self::$exists['ps']=($res===0);
    }

	/**
	 * Obtiene el comando completo de un proceso a partir de su PID.
	 * Compatibilidad comprobada en windows (powershell/wmic) y linux (ps).
	 * ## El uso en mac y bsd no está probado (uso de ps según documentación)
	 *
	 * @param int $pid
	 * @return string|null
	 */
    public static function getCommand(int $pid){
        if(self::getOSType()=='win'){
            $data=self::windows_process_list(['ProcessId'=>$pid]);
            $data=$data[0]['CommandLine']??null;
            return $data;
        }
        elseif(in_array(self::getOSType(),['mac','bsd','linux'])){
            $proc=self::newCleanEnv(['ps','-p',$pid,'-o','pid,command'])->open('temp');
            if(!$proc) return null;
            if($proc->await(30)) $proc->terminate();
            $out=$proc->out_read();
            $proc->close();
            $out=trim($out);
            if(preg_match('/\n'.$pid.'\s+((?:.|\n)+)$/', $out, $m)){
                return trim($m[1]);
            }
            return null;
        }
        else{
            return null;
        }
    }

    /**
     * Filtros:
     * - ProcessId
     * - ParentProcessId
     * - CommandLine
     * - Name
     * - ExecutablePath
     * @param array|null $eq
     * @param array|null $diff
     * @param array|null $contains
     * @param array|null $no_contains
     * @return array|null
     * @see EasyCLI::powershell_process_list()
     * @see EasyCLI::wmic_process_list()
     */
    public static function windows_process_list(?array $eq=null, ?array $diff=null, ?array $contains=null, ?array $no_contains=null){
        return self::powershell_process_list($eq, $diff, $contains, $no_contains)??self::wmic_process_list($eq, $diff, $contains, $no_contains);
    }

    /**
     * Filtros:
     * - ProcessId
     * - ParentProcessId
     * - CommandLine
     * - Name
     * - ExecutablePath
     * @param array|null $eq
     * @param array|null $diff
     * @param array|null $contains
     * @param array|null $no_contains
     * @return array|null
     * Datos:
     * - ProcessId
     * - ParentProcessId
     * - CommandLine
     * - Name
     * - ExecutablePath
     * @see EasyCLI::windows_process_list()
     */
    public static function powershell_process_list(?array $eq=null, ?array $diff=null, ?array $contains=null, ?array $no_contains=null){
        $where=[];
        $col_filter=['ProcessId', 'ParentProcessId', 'CommandLine', 'Name', 'ExecutablePath'];
        foreach((array)$eq as $name=>$filter){
            if(!in_array($name, $col_filter)) continue;
            $filter=(array)$filter;
            if(count($filter)>0){
                $where[]="\$_.".$name." -in @('".implode("','", $filter)."')";
            }
        }
        foreach((array)$diff as $name=>$filter){
            if(!in_array($name, $col_filter)) continue;
            $filter=(array)$filter;
            if(count($filter)>0){
                $where[]="\$_.".$name." -notin @('".implode("','", $filter)."')";
            }
        }
        foreach((array)$contains as $name=>$filter){
            if(!in_array($name, $col_filter)) continue;
            $filter=(array)$filter;
            if(count($filter)>0){
                $tmp=[];
                foreach($filter as $n){
                    $tmp[]="\$_.".$name." -like '*".$n."*'";
                }
                $where[]='('.implode(' -or ', $tmp).')';
                unset($tmp);
            }
        }
        foreach((array)$no_contains as $name=>$filter){
            if(!in_array($name, $col_filter)) continue;
            $filter=(array)$filter;
            if(count($filter)>0){
                $tmp=[];
                foreach($filter as $n){
                    $tmp[]="\$_.".$name." -notlike '*".$n."*'";
                }
                $where[]='('.implode(' -and ', $tmp).')';
                unset($tmp);
            }
        }
        if(count($where)>0){
            $where='| Where-Object {'.implode(' -and ', $where).'} ';
        }
        else{
            $where='';
        }
        $command='Get-CimInstance Win32_Process '.$where.'| Select-Object ProcessId,ParentProcessId,CommandLine,Name,ExecutablePath | ConvertTo-Csv';
        $proc=EasyCLI::newCleanEnv(['powershell','-Command', $command])->open('temp');
        if(!$proc) return null;
        $buffer=tmpfile();
        if($proc->await(30)) $proc->terminate();
        $proc->out_copy_to($buffer);
        $proc->close();
        fseek($buffer, 0);
        $cols=null;
        $colsCount=0;
        $list=[];
        while(is_string($line=fgets($buffer))){
            $line=trim(str_replace("\0", "", $line));
            $row=str_getcsv($line);
            if(!$cols){
                if(in_array('ProcessId', $row) && in_array('Name', $row) && in_array('ExecutablePath', $row)){
                    $cols=$row;
                    $colsCount=count($cols);
                }
                continue;
            }
            if(count($row)>$colsCount){
                $row=array_merge(array_slice($row, 0, 1),[implode(',',array_slice($row, 1, -4))],array_slice($row, -4));
            }
            if(count($row)!=$colsCount){
                continue;
            }
            $row=array_combine($cols, $row);
            $list[]=$row;
        }
        fclose($buffer);
        return $list;
    }

    /**
     * Filtros:
     * - ProcessId
     * - ParentProcessId
     * - CommandLine
     * - Name
     * - ExecutablePath
     * @param array|null $eq
     * @param array|null $diff
     * @param array|null $contains
     * @param array|null $no_contains
     * @return array|null
     * Datos:
     * - ProcessId
     * - ParentProcessId
     * - CommandLine
     * - Name
     * - ExecutablePath
     * @see EasyCLI::windows_process_list()
     */
    public static function wmic_process_list(?array $eq=null, ?array $diff=null, ?array $contains=null, ?array $no_contains=null){
        $where=[];
        $col_filter=['ProcessId', 'ParentProcessId', 'CommandLine', 'Name', 'ExecutablePath'];
        foreach((array)$eq as $name=>$filter){
            if(!in_array($name, $col_filter)) continue;
            $filter=array_map('addslashes',(array)$filter);
            if(count($filter)>0){
                $tmp=[];
                foreach($filter as $n){
                    $tmp[]=$name."='".$n."'";
                }
                $where[]='('.implode(' or ', $tmp).')';
                unset($tmp);
            }
        }
        foreach((array)$diff as $name=>$filter){
            if(!in_array($name, $col_filter)) continue;
            $filter=array_map('addslashes',(array)$filter);
            if(count($filter)>0){
                $tmp=[];
                foreach($filter as $n){
                    $tmp[]=$name."='".$n."'";
                }
                $where[]='NOT('.implode(' or ', $tmp).')';
                unset($tmp);
            }
        }
        foreach((array)$contains as $name=>$filter){
            if(!in_array($name, $col_filter)) continue;
            $filter=array_map('addslashes',(array)$filter);
            if(count($filter)>0){
                $tmp=[];
                foreach($filter as $n){
                    $tmp[]=$name." like '%".$n."%'";
                }
                $where[]='('.implode(' or ', $tmp).')';
                unset($tmp);
            }
        }
        foreach((array)$no_contains as $name=>$filter){
            if(!in_array($name, $col_filter)) continue;
            $filter=array_map('addslashes',(array)$filter);
            if(count($filter)>0){
                $tmp=[];
                foreach($filter as $n){
                    $tmp[]=$name." like '%".$n."%'";
                }
                $where[]='NOT('.implode(' or ', $tmp).')';
                unset($tmp);
            }
        }
        if(count($where)>0){
            $where=['where','"'.implode(' and ', $where).'"'];
        }
        $cmd=implode(' ', ['wmic','process', ...$where, 'get','CommandLine,Name,ExecutablePath,ParentProcessId,ProcessId','/format:csv']);
        $proc=EasyCLI::newCleanEnv($cmd)->open('temp');
        if(!$proc) return null;
        $buffer=tmpfile();
        if($proc->await(30)) $proc->terminate();
        $proc->out_copy_to($buffer);
        $proc->close();
        fseek($buffer, 0);
        $cols=null;
        $colsCount=0;
        $list=[];
        while(is_string($line=fgets($buffer))){
            $line=trim(str_replace("\0", "", $line));
            if(!$cols){
                if($line=='Node,CommandLine,ExecutablePath,Name,ParentProcessId,ProcessId'){
                    $cols=explode(',', $line);
                    $colsCount=count($cols);
                }
                continue;
            }
            $row=explode(',', $line);
            if(count($row)>$colsCount){
                $row=array_merge(array_slice($row, 0, 1),[implode(',',array_slice($row, 1, -4))],array_slice($row, -4));
            }
            if(count($row)!=$colsCount){
                continue;
            }
            $row=array_combine($cols, $row);
            unset($row['Node']);
            $list[]=$row;
        }
        fclose($buffer);
        return $list;
    }
}
