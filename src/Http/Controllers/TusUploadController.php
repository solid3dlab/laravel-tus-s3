<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Solid3d\LaravelTusS3\Contracts\TusUploadStore;
use Solid3d\LaravelTusS3\Events\FileUploadBeforeCreated;
use Solid3d\LaravelTusS3\Events\FileUploadCreated;
use Solid3d\LaravelTusS3\Events\FileUploadFinished;
use Solid3d\LaravelTusS3\Events\FileUploadStarted;
use Solid3d\LaravelTusS3\Exceptions\FileNotFoundException;
use Solid3d\LaravelTusS3\Facades\Tus;
use Solid3d\LaravelTusS3\Helpers\UploadMetadataParser;

class TusUploadController extends BaseController
{
    public function __construct(
        private TusUploadStore $store,
        private UploadMetadataParser $metadataParser,
    ) {}

    public function options(): Response
    {
        return response(
            status: 204,
            headers: Tus::headers()->forOptions()->toArray()
        );
    }

    public function post(Request $request): Response
    {
        event(new FileUploadBeforeCreated($request));

        if (! Tus::extensionIsActive('creation')) {
            return response(status: 404, headers: Tus::headers()->default()->toArray());
        }

        $length = (int) $request->header('upload-length', -1);

        if ($length < 0) {
            return response(status: 400, headers: Tus::headers()->default()->toArray());
        }

        $metadata = $this->metadataParser->parse($request->header('upload-metadata'));
        $tusFile = $this->store->create($length, $metadata);

        event(new FileUploadCreated($tusFile));

        return response(
            status: 201,
            headers: Tus::headers()->forPost(
                $tusFile,
                $this->store->offset($tusFile->id),
                $this->store->expiresAt($tusFile->id),
            )->toArray()
        );
    }

    public function head(string $id): Response
    {
        $tusFile = $this->store->find($id);

        return response(
            status: 200,
            headers: Tus::headers()->forHead(
                $tusFile,
                $this->store->offset($id),
                $this->store->expectedLength($id),
                $this->store->expiresAt($id),
            )->toArray()
        );
    }

    public function patch(Request $request, string $id): Response
    {
        $tusFile = $this->store->find($id);
        $expectedOffset = (int) $request->header('upload-offset', -1);
        $length = (int) $request->header('content-length', 0);

        if ($expectedOffset < 0) {
            return response(status: 400, headers: Tus::headers()->default()->toArray());
        }

        if ((string) $request->header('content-type') !== 'application/offset+octet-stream') {
            return response(status: 400, headers: Tus::headers()->default()->toArray());
        }

        if ($expectedOffset === 0) {
            event(new FileUploadStarted($tusFile));
        }

        [$algorithm, $hash] = $this->parseChecksumHeader($request);

        $body = $request->getContent(true);
        $offset = $this->store->append($id, $expectedOffset, $body, $length, $algorithm, $hash);

        $fresh = $this->store->find($id);

        if ($offset === $this->store->expectedLength($id)) {
            event(new FileUploadFinished($fresh));
        }

        return response(
            status: 204,
            headers: Tus::headers()
                ->forPatch($offset, $this->store->expiresAt($id))
                ->toArray()
        );
    }

    public function delete(string $id): Response
    {
        if (! Tus::extensionIsActive('termination')) {
            return response(status: 404, headers: Tus::headers()->default()->toArray());
        }

        try {
            $deleted = $this->store->abort($id);
        } catch (FileNotFoundException) {
            $deleted = false;
        }

        return response(
            status: $deleted ? 204 : 404,
            headers: Tus::headers()->default()->toArray()
        );
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function parseChecksumHeader(Request $request): array
    {
        if (! $request->hasHeader('upload-checksum') || ! Tus::extensionIsActive('checksum')) {
            return [null, null];
        }

        $parts = explode(' ', (string) $request->header('upload-checksum'), 2);

        if (count($parts) !== 2) {
            return [null, null];
        }

        return [$parts[0], $parts[1]];
    }
}
