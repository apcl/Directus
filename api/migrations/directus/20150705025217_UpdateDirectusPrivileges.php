<?php
use Ruckusing\Migration\Base as Ruckusing_Migration_Base;

class UpdateDirectusPrivileges extends Ruckusing_Migration_Base
{
    public function up()
    {
        $this->add_column('directus_privileges', 'to_view', 'tinyinteger', array(
            'limit' => 1,
            'null' => false,
            'default' => 0
        ));
        $this->add_column('directus_privileges', 'to_add', 'tinyinteger', array(
            'limit' => 1,
            'null' => false,
            'default' => 0
        ));
        $this->add_column('directus_privileges', 'to_edit', 'tinyinteger', array(
            'limit' => 1,
            'null' => false,
            'default' => 0
        ));
        $this->add_column('directus_privileges', 'to_delete', 'tinyinteger', array(
            'limit' => 1,
            'null' => false,
            'default' => 0
        ));
        $this->add_column('directus_privileges', 'to_alter', 'tinyinteger', array(
            'limit' => 1,
            'null' => false,
            'default' => 0
        ));
        $this->rename_column('directus_privileges', 'unlisted', 'listed');

        $results = $this->execute('SELECT id, permissions, listed FROM `directus_privileges`');
        $records = array();
        $updateQueryFormat = 'UPDATE `directus_privileges` SET listed=%d, to_view=%d, to_add=%d, to_edit=%d, to_delete=%d, to_alter=%d WHERE id=%d';
        foreach($results as $row) {
            $permissions = explode(',', $row['permissions']);
            $listed = $row['listed'] ? (int)$row['listed'] : 1;

            $view = 0;
            if (in_array('view', $permissions)) {
                $view = 1;
            }
            if(in_array('bigview', $permissions)) {
                $view = 2;
            }

            $add = in_array('add', $permissions) ? 1 : 0;

            $edit = 0;
            if (in_array('edit', $permissions)) {
                $edit = 1;
            }
            if(in_array('bigedit', $permissions)) {
                $edit = 2;
            }

            $delete = 0;
            if (in_array('delete', $permissions)) {
                $delete = 1;
            }
            if(in_array('bigdelete', $permissions)) {
                $delete = 2;
            }

            $alter = in_array('alter', $permissions) ? 1 : 0;

            $updateQuery = sprintf($updateQueryFormat, $listed, $view, $add, $edit, $delete, $alter, $row['id']);
            $this->execute($updateQuery);
        }

        $this->remove_column('directus_privileges', 'permissions');

    }//up()

    public function down()
    {
        // we wont use this.
    }//down()
}
