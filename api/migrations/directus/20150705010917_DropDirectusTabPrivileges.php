<?php
use Ruckusing\Migration\Base as Ruckusing_Migration_Base;

class DropDirectusTabPrivileges extends Ruckusing_Migration_Base
{
    public function up()
    {
        $this->add_column('directus_groups', 'nav_override', 'text');

        $this->execute('UPDATE `directus_groups`
                            SET `directus_groups`.nav_override = (SELECT `directus_tab_privileges`.nav_override
                                FROM `directus_tab_privileges` WHERE `directus_tab_privileges`.group_id=`directus_groups`.id)');

        $this->drop_table("directus_tab_privileges");
    }//up()

    public function down()
    {
        // we won't use this anymore
        $t = $this->create_table("directus_tab_privileges", array(
          "id"=>false,
          "options"=>"ENGINE=InnoDB DEFAULT CHARSET=utf8"
        )
      );

      //columns
      $t->column("id", "integer", array(
          "limit"=>11,
          "unsigned"=>true,
          "null"=>false,
          "auto_increment"=>true,
          "primary_key"=>true
        )
      );
      $t->column("group_id", "integer", array(
          "limit"=>11,
          "default"=>NULL
        )
      );
      $t->column("tab_blacklist", "string", array(
          "limit"=>500,
          "default"=>NULL
        )
      );
      $t->column("nav_override", "text");

      $t->finish();
    }//down()
}
