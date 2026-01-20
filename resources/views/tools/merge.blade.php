@extends('layouts.app')

@section('title', $metadata['title'])
@section('description', $metadata['description'])
@section('keywords', $metadata['keywords'])

@section('schema')
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "WebApplication",
    "name": "{{ $metadata['name'] }}",
    "description": "{{ $metadata['description'] }}",
    "url": "{{ url()->current() }}",
    "applicationCategory": "UtilityApplication",
    "operatingSystem": "Any",
    "offers": {
        "@type": "Offer",
        "price": "0",
        "priceCurrency": "USD"
    }
}
</script>
@endsection

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="bg-white rounded-lg shadow-sm p-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ $metadata['name'] }}</h1>
        <p class="text-gray-600 mb-8">{{ $metadata['description'] }}</p>

        @include('components.operation-counter')

        <div x-data="pdfMerger()" class="space-y-6">
            <!-- File Dropzone -->
            <div @drop.prevent="handleDrop($event)" @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false"
                 class="border-2 border-dashed rounded-lg p-8 text-center transition-colors"
                 :class="dragging ? 'border-indigo-500 bg-indigo-50' : 'border-gray-300'">
                <input type="file" @change="handleFiles($event.target.files)" multiple accept="application/pdf" class="hidden" id="file-input">
                <label for="file-input" class="cursor-pointer">
                    <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                        <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <p class="mt-2 text-sm text-gray-600">
                        <span class="font-semibold">Click to upload</span> or drag and drop
                    </p>
                    <p class="text-xs text-gray-500 mt-1">PDF files only</p>
                </label>
            </div>

            <!-- File List -->
            <div x-show="files.length > 0" class="space-y-2">
                <h3 class="font-semibold text-gray-900">Files to merge:</h3>
                <div class="space-y-2">
                    <template x-for="(file, index) in files" :key="index">
                        <div class="flex items-center justify-between bg-gray-50 p-3 rounded">
                            <span class="text-sm text-gray-700" x-text="file.name"></span>
                            <button @click="removeFile(index)" class="text-red-600 hover:text-red-800 text-sm">Remove</button>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Process Button -->
            <button @click="mergePdfs()" 
                    :disabled="files.length < 2 || processing"
                    class="w-full bg-indigo-600 text-white py-3 px-4 rounded-lg font-semibold hover:bg-indigo-700 disabled:bg-gray-400 disabled:cursor-not-allowed"
                    x-text="processing ? 'Processing...' : 'Merge PDFs'">
            </button>

            <!-- Download Link -->
            <div x-show="downloadUrl" class="text-center">
                <a :href="downloadUrl" :download="downloadFilename" 
                   class="inline-block bg-green-600 text-white py-3 px-6 rounded-lg font-semibold hover:bg-green-700">
                    Download Merged PDF
                </a>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('js/pdf-tools.js') }}"></script>
<script>
function pdfMerger() {
    return {
        files: [],
        dragging: false,
        processing: false,
        downloadUrl: null,
        downloadFilename: 'merged.pdf',

        handleDrop(e) {
            this.dragging = false;
            const files = Array.from(e.dataTransfer.files).filter(f => f.type === 'application/pdf');
            this.addFiles(files);
        },

        handleFiles(fileList) {
            const files = Array.from(fileList).filter(f => f.type === 'application/pdf');
            this.addFiles(files);
        },

        addFiles(newFiles) {
            this.files.push(...newFiles);
        },

        removeFile(index) {
            this.files.splice(index, 1);
        },

        async mergePdfs() {
            if (this.files.length < 2) return;
            
            this.processing = true;
            try {
                const result = await mergePdfs(this.files);
                this.downloadUrl = result.url;
                this.downloadFilename = result.filename;
                
                // Track operation
                await this.trackOperation('merge');
            } catch (error) {
                alert('Error merging PDFs: ' + error.message);
            } finally {
                this.processing = false;
            }
        },

        async trackOperation(type) {
            try {
                const response = await fetch('/operations/track', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ operation_type: type })
                });
                
                if (response.ok) {
                    const data = await response.json();
                    if (data.operations_remaining !== null && data.operations_remaining >= 0) {
                        // Update operation counter if component exists
                        window.dispatchEvent(new CustomEvent('operations-updated', { detail: data.operations_remaining }));
                    }
                }
            } catch (error) {
                console.error('Failed to track operation:', error);
            }
        }
    }
}
</script>
@endpush
@endsection
