<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Services\Pdf\RecipeCardPdfGenerator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class RecipePdfController extends Controller
{
    /**
     * Serve the locally generated recipe card PDF, generating it when missing.
     */
    public function __invoke(Recipe $recipe): BinaryFileResponse
    {
        $locale = app()->getLocale();
        $disk = Storage::disk(RecipeCardPdfGenerator::DISK);
        $path = $recipe->pdf_local_path;

        if (! $disk->exists($path)) {
            try {
                $path = RecipeCardPdfGenerator::make()->generate($recipe, $locale);
            } catch (Throwable $exception) {
                Log::error('Unable to generate the recipe card PDF.', [
                    'recipe_id' => $recipe->id,
                    'locale' => $locale,
                    'exception' => $exception,
                ]);

                throw new NotFoundHttpException((string) __('The recipe card PDF is not available.'), $exception);
            }
        }

        return response()->file($disk->path($path), ['Content-Type' => 'application/pdf']);
    }
}
