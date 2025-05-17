<?php
namespace EasyCLI;

class Proc{
    /**
     * @var float
     */
    private $start;
    /**
     * @var float|null
     */
    private $end;
    private $outfile;
    private $errfile;
    private $proc;
    private $in;
    private $out;
    private $err;
    private $last_status=[];
    private $kill=false;

    private function __construct($proc, string $outfile=null, string $errfile=null){
        $this->proc=$proc;
        $this->start=microtime(true);
        $this->status();
        if(is_string($outfile)) $this->outfile=$outfile;
        if(is_string($errfile)) $this->errfile=$errfile;
    }

    /**
     * @param array|string $command
     * @param array $descriptor_spec
     * @param string|null $cwd
     * @param array|null $env_vars
     * @param array|null $options
     * @return static|null
     * @see proc_open()
     */
    public static function start($command, array $descriptor_spec, ?string $cwd, ?array $env_vars, ?array $options){
        $pipes=[];
        $outfile=$errfile=null;
        if(($descriptor_spec[1][0]??null=='file')) $outfile=$descriptor_spec[1][1];
        if(($descriptor_spec[2][0]??null=='file')) $errfile=$descriptor_spec[2][1];
        $proc=proc_open($command, $descriptor_spec, $pipes, $cwd, $env_vars, $options);
        if(!$proc) return null;
        $new=new static($proc, empty($pipes[1])?$outfile:null, empty($pipes[2])?$errfile:null);
        if(!empty($pipes[0])) $new->in=&$pipes[0];
        if(!empty($pipes[1])) $new->out=&$pipes[1];
        elseif(is_resource($descriptor_spec[1]??null)) $new->out=$descriptor_spec[1];
        if(!empty($pipes[2])) $new->err=&$pipes[2];
        elseif(is_resource($descriptor_spec[2]??null)) $new->err=$descriptor_spec[2];
        return $new;
    }

    /**
     * @return float
     */
    public function getStartTime(){
        return $this->start;
    }

    /**
     * @return float|null
     */
    public function getEndTime(){
        return $this->end;
    }

    /**
     * Obtiene la dirección del archivo en el que se guardó la salida del proceso
     * @return string|null Devuelve null si no se encontró el archivo
     */
    public function getOutfile(){
        return $this->outfile;
    }

    /**
     * Obtiene la dirección del archivo en el que se guardó la salida de errores del proceso
     * @return string|null Devuelve null si no se encontró el archivo
     */
    public function getErrfile(){
        return $this->errfile;
    }

    /**
     * @return array Devuelve el resultado de {@see proc_get_status()}
     */
    public function status(){
        if(!$this->is_closed()){
            $status=proc_get_status($this->proc);
            if($status) $this->last_status=$status;
            else{
                $this->last_status['exitcode']=0;
                $this->last_status['running']=false;
            }
        }
        elseif(!empty($this->last_status['running'])){
            $this->last_status['exitcode']=0;
            $this->last_status['running']=false;
        }
        if(empty($this->last_status['running']) && !isset($this->end)){
            $this->end=microtime(true);
        }
        return $this->last_status;
    }

    public function get_cmd(){
        return $this->last_status['command']??null;
    }

    /**
     * Obtiene el PID del proceso
     * @return null|int
     */
    public function pid(){
        return $this->last_status['pid']??null;
    }

    /**
     * @return bool Devuelve TRUE si el proceso se encuentra en ejecución
     */
    public function is_running(){
        return !empty($this->status()['running']);
    }

    public function exec_time(){
        $this->status();
        return ($this->end??microtime(true))-$this->start;
    }

    public function in(){
        return $this->in;
    }

    public function out(){
        return $this->out;
    }

    public function err(){
        return $this->err;
    }

    public function in_write($str){
        if(empty($this->in)) return false;
        $resp=fwrite($this->in, $str);
        return $resp;
    }

    public function in_close(){
        if(isset($this->in)) fclose($this->in) && ($this->in=null);
    }

    public function out_unset(){
        if(isset($this->out)) $this->out=null;
    }

    public function err_unset(){
        if(isset($this->err)) $this->err=null;
    }

    public function out_read(){
        if(empty($this->out)) return false;
        ob_start();
        fpassthru($this->out);
        return ob_get_clean();
    }

    public function err_read(){
        if(empty($this->err)) return false;
        ob_start();
        fpassthru($this->err);
        return ob_get_clean();
    }

    public function out_passthru(){
        if(empty($this->out)) return false;
        return fpassthru($this->out);
    }

    public function err_passthru(){
        if(empty($this->err)) return false;
        return fpassthru($this->err);
    }

    /**
     * @param resource $stream
     * @return false|int
     */
    public function out_copy_to($stream){
        if(empty($this->out)) return false;
        return stream_copy_to_stream($this->out, $stream);
    }

    /**
     * @param resource $stream
     * @return false|int
     */
    public function err_copy_to($stream){
        if(empty($this->err)) return false;
        return stream_copy_to_stream($this->err, $stream);
    }

    public function is_closed(){
        return empty($this->proc);
    }


    /**
     * @return bool
     */
    public function terminate(){
        return $this->is_closed() || proc_terminate($this->proc);
    }

    /**
     * Espera al proceso y cierrar todos los pipes
     * # IMPORTANTE:
     * ## Esto espera hasta que el proceso finalice, por lo que bloquea la ejecución actual
     * - Se recomienda verificar primero si el proceso sigue en ejecución {@see Proc::is_running()}
     * - Al cerrar el proceso no podrá leer los buffer de salida como {@see Proc::out_read()} o {@see Proc::err_read()}
     * @return int
     */
    public function close(){
        $this->in_close();
        if(isset($this->out)){
            fclose($this->out);
            $this->out=null;
        }
        if(isset($this->err)){
            fclose($this->err);
            $this->err=null;
        }
        $this->status();
        if($this->is_closed()) return $this->last_status['exitcode'];
        // Aquí se bloquea el proceso actual hasta que el otro proceso termine
        $this->last_status['exitcode']=proc_close($this->proc);
        $this->proc=null;
        $this->status();
        return $this->last_status['exitcode'];
    }

    /**
     * @param int $maxwait Máximo de espera en segundos. Default=10. Min=1
     * @param float $wait_sec Segundos de espera entre cada intervalo. Min=0.1
     * @return bool
     * @see Proc::is_running()
     */
    public function await(int $maxwait=10, float $wait_sec=0.2){
        $wait_sec=max(0.1, $wait_sec);
        $sleep=intval($wait_sec);
        $usleep=intval(($wait_sec-$sleep)*1e6);
        $maxtime=microtime(true)+max($maxwait, 1);
        while(microtime(true)<$maxtime && $this->is_running()){
            if($usleep) usleep($usleep);
            if($sleep) sleep($sleep);
        }
        return $this->is_running();
    }

    public function pipes_info(){
        $info=[];
        if(isset($this->in)){
            $info[0]=stream_get_meta_data($this->in);
        }
        if(isset($this->out)){
            $info[1]=stream_get_meta_data($this->out);
        }
        if(isset($this->err)){
            $info[2]=stream_get_meta_data($this->err);
        }
        return $info;
    }

    /**
     * Determina si se termina el proceso al destruir este objeto, si en ese momento el proceso continua en ejecución
     * @param bool|null $signal Si es NULL no cambia el valor
     * @return bool Default: FALSE
     */
    public function terminateOnDestruct(?bool $value=null): bool{
        if(is_bool($value)) $this->kill=$value;
        return $this->kill;
    }

    public function __destruct(){
        if($this->kill) $this->terminate();
        $this->close();
    }
}
