<?php

namespace Pho\Stream\Controller;

use Pho\Stream\Authorization;
use Pho\Stream\Exception\ValidationFailedException;
use Pho\Stream\Model\FeedModel;
use Psr\Http\Message\ServerRequestInterface;
use Rakit\Validation\Validator;
use Teapot\StatusCode;
use Zend\Diactoros\Response\JsonResponse;

class FeedController
{
    private $feedModel;
    private $auth;

    public function __construct(FeedModel $feedModel, Authorization $authorization)
    {
        $this->feedModel = $feedModel;
        $this->auth = $authorization;
    }

    public function addActivity($feed_slug, $user_id, ServerRequestInterface $request)
    {
        $this->auth->authorize($feed_slug, $user_id, 'feed', 'write');

        $body = $request->getBody()->getContents();
        $body = json_decode($body, true) ?? [];

        $validator = new Validator();
        $validation = $validator->validate($body, [
            'actor' => 'required',
            'verb' => 'required',
            'object' => 'required',
            'time' => 'date:Y-m-d\TH:i:s.u',
        ]);

        if ($validation->fails()) {
            throw new ValidationFailedException($validation->errors());
        }

        $actor = $body['actor'];
        $verb = $body['verb'];
        $object = $body['object'];

        $timeFormat = 'Y-m-d\TH:i:s.v';
        if (isset($body['time'])) {
            $time = date($timeFormat, strtotime($body['time']));
        }
        else {
            $time = date($timeFormat, time());
        }
        $otherFields = [
            'time' => $time,
        ];
        $otherFields = $otherFields + array_diff_key($body, array_flip(['actor', 'verb', 'object']));

        $id = $this->feedModel->addActivity($feed_slug, $user_id, $actor, $verb, $object, $otherFields);

        $res = [
            'id' => $id,
            'actor' => $actor,
            'verb' => $verb,
            'object' => $object,
        ] + $otherFields;

        return new JsonResponse($res);
    }

    public function follow($feed_slug, $user_id, ServerRequestInterface $request)
    {
        $this->auth->authorize($feed_slug, $user_id, 'follower', 'write');

        $bodyContents = $request->getBody()->getContents();
        $bodyContents = json_decode($bodyContents, true) ?? [];

        $validator = new Validator();
        $validation = $validator->validate($bodyContents, [
            'target' => "required|not_in:{$feed_slug}:{$user_id}",
        ]);

        if ($validation->fails()) {
            throw new ValidationFailedException($validation->errors());
        }

        $target = $bodyContents['target'];

        /*
        if (! $this->feedModel->feedExists($target)) {
            return new JsonResponse([
                'target' => "Invalid target {$target}",
            ], StatusCode::BAD_REQUEST);
        }
        */

        $ret = $this->feedModel->follow("{$feed_slug}:{$user_id}", $target);

        return new JsonResponse([
            'success' => boolval($ret),
        ]);
    }

    public function get($feed_slug, $user_id, ServerRequestInterface $request)
    {
        $this->auth->authorize($feed_slug, $user_id, 'feed', 'read');

        $queryParams = $request->getQueryParams();

        $validator = new Validator();
        $validation = $validator->validate($queryParams, [
            'limit' => 'integer',
            'offset' => 'integer',
        ]);

        if ($validation->fails()) {
            throw new ValidationFailedException($validation->errors());
        }

        $limit = null;
        if (isset($queryParams['limit'])) {

            $limit = intval($queryParams['limit']);

            $validation = $validator->validate([
                'limit' => $limit,
            ], [
                'limit' => 'min:1|max:100',
            ]);

            if ($validation->fails()) {
                throw new ValidationFailedException($validation->errors());
            }
        }

        $offset = 0;
        if (isset($queryParams['offset'])) {

            $offset = intval($queryParams['offset']);

            $validation = $validator->validate([
                'offset' => $offset,
            ], [
                'offset' => 'min:0',
            ]);

            if ($validation->fails()) {
                throw new ValidationFailedException($validation->errors());
            }
        }

        $feed = $this->feedModel->get($feed_slug, $user_id, $limit, $offset);

        if (is_null($feed)) {
            return new JsonResponse([
                'results' => null,
            ], StatusCode::NOT_FOUND);
        }

        $res = [
            'results' => $feed,
        ];

        return new JsonResponse($res);
    }
}
