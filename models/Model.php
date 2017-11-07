<?php
class ModelModel extends Medoo {
    public function __construct(){
        $db = \Yaf\Registry::get("dbconfig")['medoo'];
        parent::__construct($db);
    }
}
?>
