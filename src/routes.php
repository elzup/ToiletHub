<?php
// Routes
define('Q_SELECT_TOILET', 'id, name, type, ST_X(ST_TRANSFORM(position, 4612)) AS long, ST_Y(ST_TRANSFORM(position, 4612)) AS lat');

$app->get('/toilets/{id}', function ($request, $response, $args) {
    $query = 'select ' . Q_SELECT_TOILET .' from toilets where id = ' . intval($args['id']);
    $toilets = ORM::for_table('toilets')->raw_query($query)->find_array();
    $toilet = $toilets[0];
    wrap_toilet($toilet);
    $body = $response->getBody();
    $body->write(json_encode($toilet));
    return $response->withHeader('Content-Type', 'application/json')->withBody($body);
});

function wrap_toilet(&$toilet) {
    $toilet['options'] = ORM::for_table('options')->where_equal('toilet_id', $toilet['id'])->find_array();
    $toilet['reviews'] = ORM::for_table('reviews')->where_equal('toilet_id', $toilet['id'])->find_array();
}

function wrap_toilets(&$toilets) {
    $ids = array_map(function($e) { return $e['id']; }, $toilets);
    $ids_in = '(' . implode(',', $ids). ')';
    $options = ORM::for_table('options')->raw_query("select * from options where toilet_id in {$ids_in};")->find_array();
    $reviews = ORM::for_table('reviews')->raw_query("select * from reviews where toilet_id in {$ids_in};")->find_array();
    $toilets_map = array();
    foreach ($options as $option) {
        @$toilets_map[$option['toilet_id']][0][] = $option;
    }
    foreach ($reviews as $review) {
        @$toilets_map[$review['toilet_id']][1][] = $review;
    }
    foreach ($toilets as &$toilet) {
        $toilet['options'] = $toilets_map[$toilet['id']][0];
        $toilet['reviews'] = $toilets_map[$toilet['id']][1];
    }
}

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
    $query = 'select ' . Q_SELECT_TOILET . ' from toilets order by ST_Distance( position,
        ST_GeomFromText(\'POINT(' . floatval($request->getParam('long')) . ' ' . floatval($request->getParam('lat')) . ')\', 4612)) limit 10;';
    $toilets = ORM::for_table('toilets')->raw_query($query)->find_array();
    $body = $response->getBody();
    wrap_toilets($toilets);
    $body->write(json_encode($toilets));
    return $response->withHeader('Content-Type', 'application/json')->withBody($body);
});

$app->get('/reviews/{id}', function ($request, $response, $args) {
    $reviews = ORM::for_table('reviews')->where_equal('id', $args['id'])->find_array();
    $body = $response->getBody();
    $body->write(json_encode($reviews[0]));
    return $response->withHeader('Content-Type', 'application/json')->withBody($body);
});

$app->get('/reviews/', function ($request, $response, $args) {
    $reviews = ORM::for_table('reviews')->where_equal('toilet_id', $request->getParam('toilet_id'))->find_array();
    $body = $response->getBody();
    $body->write(json_encode($reviews));
    return $response->withHeader('Content-Type', 'application/json')->withBody($body);
});

$app->get('/toilets/ranking/', function ($request, $response, $args) {
    $where_list = array();
    if ($request->getParam('is_man')) {
        $where_list[] = 'is_man=' . $request->getParam('is_man');
    }
    if ($request->getParam('age')) {
        $where_list[] = 'age=' . $request->getParam('age');
    }
    if ($request->getParam('has_child')) {
        $where_list[] = 'has_child=' . $request->getParam('has_child');
    }
    $where = '';
    if (!empty($where_list)) {
        $where = ' where ' . implode(' and ', $where_list);
    }
    $query = 'select ' . Q_SELECT_TOILET . ' from toilets as T1
inner join (
    select toilet_id as tid, avg(rate) as rate_avg from reviews where user_id in (
        select T3.id from users as T3 ' . $where . '
    ) group by toilet_id
) as T2 on T1.id = T2.tid
order by rate_avg DESC;';
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
        $k = rand(0, 6);
        echo $k . " ";
        for ($j = 0; $j < 3; $j++) {
            echo (($k >> $j) & 1) == 1 ? "+" : "-";
            if ((($k >> $j) & 1) == 0) {
                continue;
            }
            $in_query = "($i, '{$option_names[$j]}', '備考 $i')";
            // $in = array( 'toilet_id' => $i, 'name' => $option_names[$j], 'description' => "備考 $i");
            // ORM::for_table('options')->create($in)->save();
            ORM::raw_execute("$insert_options_query_head $in_query");
        }
        echo "\n";
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
