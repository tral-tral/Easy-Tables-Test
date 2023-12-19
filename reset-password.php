<?php
if(!defined('ABSPATH')) exit; // Exit if accessed directly

class Reset_password{


    private $table;
    private $TABLE_NAME = 'reset_password';
    private $table_args = [
        'id'        => ['type' => 'int', 'auto' => true, 'primary' => true ],
        'user_id'   => ['type' => 'string' ],
        'token'     => ['type' => 'string', 'unique' => true ],
        'datetime'  => [ 'type' => 'datetime', 'default'=>'CURRENT_TIMESTAMP', 'on_update' => 'CURRENT_TIMESTAMP']
    ];




    public function __construct(){
        $this->table = new Table($this->TABLE_NAME, $this->table_args);

        add_action('daily_tasks',[$this, 'clean_up']);

    }

    //Daily clean up to remove old entries.
    public function clean_up(){
        $timeTwoHoursAgo = gmdate('Y-m-d H:i:s', strtotime('-2 hours' ) );
        $this->table->delete(
            ['datetime' => ['value' => $timeTwoHoursAgo, 'compare' => '<' ]]
        );
    }

    private function insert( $user_id, $token  ){
        return $this->table->insert( [ 'user_id' => $user_id, 'token' => $token] );
    }


    public function get_by_userid( $email ){
        $timeTwoHoursAgo = gmdate('Y-m-d H:i:s', strtotime('-2 hours' ) );
        return $this->table->get_row(
            ['user_id' => ['value' => $email], 'datetime' =>[ 'value' => $timeTwoHoursAgo, 'compare' => '>' ] ]
        );
    }

    public function get_by_id( $id ){
        $timeTwoHoursAgo = gmdate('Y-m-d H:i:s', strtotime('-2 hours' ) );
        return $this->table->get_row(
            ['id' => ['value' => $id], 'datetime' =>[ 'value' => $timeTwoHoursAgo, 'compare' => '>' ] ]
        );
    }


    public function get( $token ){
        $timeTwoHoursAgo = gmdate('Y-m-d H:i:s', strtotime('-2 hours' ) );
        return $this->table->get_row( ['token' => [ 'value' => $token ], 'datetime' =>[ 'value' => $timeTwoHoursAgo, 'compare' => '>' ] ]);
    }


    public function delete( $id ){
        return $this->table->delete(
            ['id' => ['value' => $id] ]
        );
    }



    public function process($user_id){

        $maxAttempts = 5; // Define max attempts to avoid infinite loop
        $attempt = 0;

        do {
            $token = bin2hex(random_bytes(32));
            $attempt++;
            if ($attempt > $maxAttempts) {
                throw new \Exception('Maximum attempts reached for token generation.');
            }
        } while (!$this->insert( $user_id, $token) );


        $email = get_userdata( $user_id )->user_email;

        $body = sprintf("
※ This is an automated response email ※

Greetings from My Site,

We received a request to reset the password for your account at My Site. If you didn't make this request, please ignore this email. If you did, follow the instructions below to reset your password.

To reset your password:
Click on the link below or copy and paste it into your browser:
https://mysite.com/reset-password?t=%s
This link will be active for the next 2 hours. After that, you'll need to request a new password reset if you still want to change your password.

Follow the on-screen instructions to create a new password.

If you encounter any problems or have questions, reach out to our support team at support@mysite.com.

Remember, for security reasons, My Site will never ask you for your password via email, phone, or any other communication channel.

Stay safe and enjoy your night out!

Warm regards,
The My Site Team
", $token);


        mailing( $email,'Password Reset for Your My Site Account', $body );

    }


    function reset_password( $reset_id, $password ){

        $reset_object = $this->get_by_id($reset_id );

        if( empty( $reset_object ) ) return;

        $user_id = $reset_object['user_id'];

        $data = [
            'ID' => $user_id,
            'user_pass' => $password,
        ];

        add_filter( 'send_password_change_email', '__return_false' );



        $updateuser = wp_update_user( $data );

        if( is_wp_error( $updateuser) ){
            return false;
        }

        $this->delete( $reset_id );

        $email = get_userdata( $user_id )->user_email;

        $body = "
※ This is an automated response email ※

Greetings from My Site,

This is to inform you that the password for your account at My Site has been successfully reset. If you initiated this change, you can now log in using your new password.

If you did not request a password reset or believe this action was taken in error, please contact our support team immediately at support@mysite.com. It's crucial to ensure your account remains secure and only accessible by you.

Some security tips:

1. Never share your password with anyone.
2. Regularly update your password and choose a strong, unique combination of characters.
3. Always make sure you're on the official My Site website before entering any login details.

Thank you for being a part of My Site. Your security is our top priority.

Best regards,
The My Site Team";

        mailing( $email,'Your My Site Password Has Been Reset', $body   );

        return true;
    }


}
