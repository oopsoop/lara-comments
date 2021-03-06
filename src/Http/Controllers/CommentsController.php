<?php

namespace tizis\laraComments\Http\Controllers;

use Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use tizis\laraComments\Entity\Comment;
use tizis\laraComments\Http\Requests\EditRequest;
use tizis\laraComments\Http\Requests\GetRequest;
use tizis\laraComments\Http\Requests\ReplyRequest;
use tizis\laraComments\Http\Requests\SaveRequest;
use tizis\laraComments\Http\Resources\CommentResource;
use tizis\laraComments\UseCases\CommentService;
use tizis\laraComments\UseCases\VoteService;

class CommentsController extends Controller
{
    use ValidatesRequests, AuthorizesRequests;

    protected $commentService;
    protected $voteService;
    protected $policyPrefix;

    /**
     * CommentsController constructor.
     * @param VoteService $voteService
     */
    public function __construct(VoteService $voteService)
    {
        $this->middleware(['web', 'auth'], ['except' => ['get']]);
        $this->policyPrefix = config('comments.policy_prefix');
        $this->voteService = $voteService;
    }

    /**
     * Creates a new comment for given model.
     *
     * @param SaveRequest $request
     * @return array|\Illuminate\Http\RedirectResponse
     */
    public function store(SaveRequest $request)
    {
        $modelPath = $request->commentable_type;
        $message = CommentService::htmlFilter($request->message);

        if (!class_exists($modelPath)) {
            throw new \DomainException('Model don\'t exists');
        }

        $model = new $modelPath;

        if (!CommentService::isCommentable($model)) {
            throw new \DomainException('Model is\'t commentable');
        }

        $model = $model::findOrFail($request->commentable_id);
        $comment = CommentService::createComment(Auth::user(), $model, $message);

        $resource = new CommentResource($comment);

        return $request->ajax() ? ['success' => true, 'comment' => $resource] : redirect()->to(url()->previous() . '#comment-' . $comment->id);
    }

    /**
     * @param GetRequest $request
     * @return array
     */
    public function get(GetRequest $request): array
    {
        $modelPath = $request->commentable_type;
        $modelId = $request->commentable_id;
        $orderBy = CommentService::orderByRequestAdapter($request);

        if (!class_exists($modelPath)) {
            throw new \DomainException('Model don\'t exists');
        }

        $model = new $modelPath;

        if (!CommentService::isCommentable($model)) {
            throw new \DomainException('Model is\'t commentable');
        }

        $model = $modelPath::where(['id' => $modelId])->first();

        $count = $model->comments()->count();
        $comments = $model->comments()
            ->parentless()
            ->orderBy($orderBy['column'], $orderBy['direction'])
            ->get();

        $resource = CommentResource::collection($comments);

        return ['success' => true, 'comments' => $resource, 'count' => $count];
    }


    /**
     * Updates the message of the comment.
     * @param EditRequest $request
     * @param Comment $comment
     * @return array|\Illuminate\Http\RedirectResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function update(EditRequest $request, Comment $comment)
    {
        $this->authorize($this->policyPrefix . '.edit', $comment);

        $message = CommentService::htmlFilter($request->message);

        CommentService::updateComment($comment, $message);

        $resource = new CommentResource($comment);

        return $request->ajax()
            ? ['success' => true, 'comment' => $resource]
            : redirect()->to(url()->previous() . '#comment-' . $comment->id);

    }

    /**
     * Deletes a comment.
     * @param Request $request
     * @param Comment $comment
     * @return array|\Illuminate\Http\RedirectResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function destroy(Request $request, Comment $comment)
    {
        $this->authorize($this->policyPrefix . '.delete', $comment);

        try {
            CommentService::deleteComment($comment);
            $response = ['success' => true];
        } catch (\DomainException $e) {
            $response = ['success' => false, 'message' => $e->getMessage()];
        }

        return $request->ajax() ? $response : redirect()->back();
    }

    /**
     * Reply to comment
     *
     * @param Request $request
     * @param Comment $comment
     * @return array|\Illuminate\Http\RedirectResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function reply(ReplyRequest $request, Comment $comment)
    {
        $this->authorize($this->policyPrefix . '.reply', $comment);
        $message = CommentService::htmlFilter($request->message);

        $reply = CommentService::createComment(Auth::user(), $comment->commentable, $message, $comment);
        $resource = new CommentResource($reply);

        return $request->ajax() ? ['success' => true, 'comment' => $resource] : redirect()->to(url()->previous() . '#comment-' . $reply->id);
    }

}
