<?php
// Routes

$app->get('/toilets/{id}', function ($request, $response, $args) {
    $toilets = ORM::for_table('toilets')
        ->raw_query('select id, name, type,
ST_X(ST_TRANSFORM(position, 4612)) AS long,
ST_Y(ST_TRANSFORM(position, 4612)) AS lat
from toilets where id = ' . intval($args['id']))->find_array();
    $body = $response->getBody();
    $body->write(json_encode($toilets[0]));
    return $response->withHeader('Content-Type', 'application/json')->withBody($body);
});

$app->post('/users/register', function ($request, $response, $args) {
    $in = array(
        'is_man' => $request->getParam('is_man'),
        'age' => intval(intval($request->getParam('age')) / 10) * 10,
        'has_child' => $request->getParam('has_child'));
    $user = ORM::for_table('users')->create($in);
    $user->save();
    $body = $response->getBody();
    $body->write(json_encode($user));
    return $response->withHeader('Content-Type', 'application/json')->withBody($body);
});

$app->get('/toilets/search/', function ($request, $response, $args) {
    $query = 'select id, name, type, ST_X(ST_TRANSFORM(position, 4612)) AS long, ST_Y(ST_TRANSFORM(position, 4612)) AS lat
from toilets order by ST_Distance( position,
        ST_GeomFromText(\'POINT(' . floatval($request->getParam('long')) . ' ' . floatval($request->getParam('lat')) . ')\', 4612)) limit 10;';
    $toilets = ORM::for_table('toilets')->raw_query($query)->find_array();
    $body = $response->getBody();
    $body->write(json_encode($toilets));
    return $response->withHeader('Content-Type', 'application/json')->withBody($body);
});

$app->get('/seed', function ($request, $response, $args) {
    $ages = array(10, 20, 30, 40, 50);
    // create 100 users
    ORM::for_table('reviews')->delete_many();
    ORM::for_table('options')->delete_many();
    ORM::for_table('users')->delete_many();
    ORM::for_table('toilets')->delete_many();
    ORM::raw_execute("select setval('users_id_seq', 1, false)");
    ORM::raw_execute("select setval('toilets_id_seq', 1, false)");
    ORM::raw_execute("select setval('reviews_id_seq', 1, false)");
    for ($i = 1; $i <= 100; $i++) {
        // 男女 1:1
        // 年代 10 - 50
        // 子持ち 1:20
        $in = array(
            'is_man' => rand(0, 1) == 1,
            'age' => $ages[array_rand($ages)],
            'has_child' => rand(0, 20) < 1);
        ORM::for_table('users')->create($in)->save();
    }

    // create 20 toilets
    $insert_query_head = 'INSERT INTO toilets (name, type, position) values';
    $insert_options_query_head = 'INSERT INTO options (toilet_id, name, description) values';
    $option_names = array('車いす', 'ベビーシート', 'オストメイト');
    for ($i = 1; $i <= 20; $i++) {
        // name "トイレ0" - "トイレ20"
        // type {1: 公衆トイレ, 2: 有料トイレ, 3: 公共施設トイレ }
        // position 北千住駅を中心に ± 0.01
        $rand_type = rand(0, 10);
        $lat = 35.748558 + ((mt_rand() / mt_getrandmax()) - 0.5) * 0.02;
        $long = 139.806355 + ((mt_rand() / mt_getrandmax()) - 0.5) * 0.02;
        $pos = "ST_GeomFromText('POINT($long $lat)', 4612)";
        $in_query = "('トイレ{$i}', $rand_type, $pos)";
        // ex: insert into toilets (name, type, position) values ('トイレ9999', 2, ST_GeomFromText('POINT(-71.060316 48.432044)', 4612));

        // create 20 toilets
        ORM::raw_execute("$insert_query_head $in_query;");
        $k = rand(0, 8);
        for ($j = 0; $j < 3; $j++) {
            if (($k >> $j && 1) == 0) {
                continue;
            }
            $in_query = "($i, '{$option_names[$j]}', '備考 $i')";
            // $in = array( 'toilet_id' => $i, 'name' => $option_names[$j], 'description' => "備考 $i");
            // ORM::for_table('options')->create($in)->save();
            ORM::raw_execute("$insert_options_query_head $in_query");
        }
    }

    // create 20 * 10 reviews
    foreach (ORM::for_table('users')->find_many() as $user) {
        $user->id;
        for ($i = 0; $i < 5; $i++) {
            $in = array(
                'user_id' => $user->id,
                'toilet_id' => rand(1, 20),
                'rate' => rand(1, 5),
                'comment' => "コメント $i");
            ORM::for_table('reviews')->create($in)->save();
        }
    }
});
