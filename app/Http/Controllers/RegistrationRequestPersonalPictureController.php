<?php

namespace App\Http\Controllers;

use App\Filament\Resources\RegistrationRequests\RegistrationRequestResource;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RegistrationRequestPersonalPictureController extends Controller
{
    public function __invoke(
        int $registrationRequest
    ): BinaryFileResponse {
        /*
         * Resolve the Registration Request through the exact same
         * authoritative scope used by the Filament Resource.
         *
         * This prevents IDOR access by changing the request ID.
         */
        $record =
            RegistrationRequestResource::getEloquentQuery()
                ->whereKey(
                    $registrationRequest
                )
                ->firstOrFail();

        $storedPath =
            $record->personal_picture_path;

        abort_unless(
            is_string(
                $storedPath
            ),
            404
        );

        $storedPath =
            trim(
                str_replace(
                    '\\',
                    '/',
                    $storedPath
                )
            );

        abort_if(
            $storedPath === ''
                || str_contains(
                    $storedPath,
                    "\0"
                ),
            404
        );

        /*
         * RegistrationSubmissionService stores pictures only in:
         *
         * registration-requests/{center_id}/personal-pictures/
         *
         * Never trust a persisted path without re-validating it.
         */
        $expectedPrefix =
            'registration-requests/'
            .$record->center_id
            .'/personal-pictures/';

        abort_unless(
            str_starts_with(
                $storedPath,
                $expectedPrefix
            ),
            404
        );

        /*
         * store() writes one generated filename directly inside
         * the expected directory.
         *
         * Nested paths, "." and ".." are therefore never valid
         * registration picture locations.
         */
        $filename =
            substr(
                $storedPath,
                strlen(
                    $expectedPrefix
                )
            );

        abort_if(
            $filename === ''
                || str_contains(
                    $filename,
                    '/'
                )
                || $filename === '.'
                || $filename === '..',
            404
        );

        $disk =
            Storage::disk(
                'local'
            );

        abort_unless(
            $disk->exists(
                $storedPath
            ),
            404
        );

        $absolutePath =
            $disk->path(
                $storedPath
            );

        abort_unless(
            is_file(
                $absolutePath
            ),
            404
        );

        $mimeType =
            $disk->mimeType(
                $storedPath
            );

        /*
         * Validate the real served file type again.
         *
         * Do not rely only on the extension stored in the path.
         */
        abort_unless(
            is_string(
                $mimeType
            )
                && in_array(
                    $mimeType,
                    [
                        'image/jpeg',
                        'image/png',
                        'image/webp',
                    ],
                    true
                ),
            404
        );

        return response()->file(
            $absolutePath,
            [
                'Content-Type' => $mimeType,

                /*
                 * This is private identity information.
                 *
                 * Do not permit shared or browser caches to retain
                 * the image after the authenticated request.
                 */
                'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',

                'Pragma' => 'no-cache',

                'Content-Disposition' => 'inline',

                'X-Content-Type-Options' => 'nosniff',

                'Cross-Origin-Resource-Policy' => 'same-origin',

                'Referrer-Policy' => 'no-referrer',
            ]
        );
    }
}
