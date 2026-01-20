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

        <div x-data="imageToPdfConverter()" class="space-y-6">
            <!-- File Upload -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select image files</label>
                <input type="file" @change="handleFiles($event.target.files)" multiple accept="image/*" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            </div>

            <!-- File List -->
            <div x-show="files.length > 0" class="space-y-2">
                <h3 class="font-semibold text-gray-900">Images to convert:</h3>
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
            <button @click="convertToPdf()" 
                    :disabled="files.length === 0 || processing"
                    class="w-full bg-indigo-600 text-white py-3 px-4 rounded-lg font-semibold hover:bg-indigo-700 disabled:bg-gray-400 disabled:cursor-not-allowed"
                    x-text="processing ? 'Converting...' : 'Convert to PDF'">
            </button>

            <!-- Download Link -->
            <div x-show="downloadUrl" class="text-center">
                <a :href="downloadUrl" :download="downloadFilename" 
                   class="inline-block bg-green-600 text-white py-3 px-6 rounded-lg font-semibold hover:bg-green-700">
                    Download PDF
                </a>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('js/pdf-tools.js') }}"></script>
<script>
function imageToPdfConverter() {
    return {
        files: [],
        processing: false,
        downloadUrl: null,
        downloadFilename: 'converted.pdf',

        handleFiles(fileList) {
            const files = Array.from(fileList).filter(f => f.type.startsWith('image/'));
            this.files.push(...files);
        },

        removeFile(index) {
            this.files.splice(index, 1);
        },

        async convertToPdf() {
            if (this.files.length === 0) return;
            
            this.processing = true;
            try {
                const result = await imageToPdf(this.files);
                this.downloadUrl = result.url;
                this.downloadFilename = result.filename;
                
                await this.trackOperation('image-to-pdf');
            } catch (error) {
                alert('Error converting images: ' + error.message);
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
                    window.dispatchEvent(new CustomEvent('operations-updated', { detail: data.operations_remaining }));
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
