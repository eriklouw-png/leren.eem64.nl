<?php
/** Idempotent schema upgrade for recoverable deletion. Run outside transactions. */
function trash_schema(PDO $pdo):void{
    foreach(['subjects','ai_source_collections'] as $table){
        $columns=$pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        if(!in_array('deleted_at',$columns,true)){
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL");
        }
    }
}
