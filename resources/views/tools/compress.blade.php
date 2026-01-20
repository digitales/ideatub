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

        <div x-data="pdfCompressor()" class="space-y-6">
            <!-- File Upload -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select PDF file</label>
                <input type="file" @change="handleFile($event.target.files)" accept="application/pdf" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            </div>

            <!-- File Info -->
            <div x-show="file" class="bg-gray-50 p-4 rounded">
                <p class="text-sm text-gray-700">
                    <span class="font-semibold">File:</span> <span x-text="file?.name"></span><br>
                    <span class="font-semibold">Size:</span> <span x-text="formatFileSize(originalSize)"></span>
                </p>
            </div>

            <!-- Compression Quality -->
            <div x-show="file">
                <label class="block text-sm font-medium text-gray-700 mb-2">Compression quality</label>
                <select x-model="quality" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="high">High (larger file, better quality)</option>
                    <option value="medium" selected>Medium (balanced)</option>
                    <option value="low">Low (smaller file, lower quality)</option>
                </select>
            </div>

            <!-- Process Button -->
            <button @click="compressPdf()" 
                    :disabled="!file || processing"
                    class="w-full bg-indigo-600 text-white py-3 px-4 rounded-lg font-semibold hover:bg-indigo-700 disabled:bg-gray-400 disabled:cursor-not-allowed"
                    x-text="processing ? 'Compressing...' : 'Compress PDF'">
            </button>

            <!-- Results -->
            <div x-show="compressedSize > 0" class="bg-green-50 p-4 rounded">
                <p class="text-sm text-gray-700">
                    <span class="font-semibold">Original size:</span> <span x-text="formatFileSize(originalSize)"></span><br>
                    <span class="font-semibold">Compressed size:</span> <span x-text="formatFileSize(compressedSize)"></span><br>
                    <span class="font-semibold">Reduction:</span> <span x-text="reductionPercent + '%'"></span>
                </p>
            </div>

            <!-- Download Link -->
            <div x-show="downloadUrl" class="text-center">
                <a :href="downloadUrl" :download="downloadFilename" 
                   class="inline-block bg-green-600 text-white py-3 px-6 rounded-lg font-semibold hover:bg-green-700">
                    Download Compressed PDF
                </a>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('js/pdf-tools.js') }}"></script>
<script>
function pdfCompressor() {
    return {
        file: null,
        originalSize: 0,
        compressedSize: 0,
        quality: 'medium',
        processing: false,
        downloadUrl: null,
        downloadFilename: 'compressed.pdf',

        handleFile(fileList) {
            if (fileList.length === 0) return;
            this.file = fileList[0];
            this.originalSize = this.file.size;
        },

        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        },

        get reductionPercent() {
            if (this.originalSize === 0) return 0;
            return Math.round((1 - this.compressedSize / this.originalSize) * 100);
        },

        async compressPdf() {
            if (!this.file) return;
            
            this.processing = true;
            try {
                const result = await compressPdf(this.file, this.quality);
                this.downloadUrl = result.url;
                this.downloadFilename = result.filename;
                this.compressedSize = result.size;
                
                await this.trackOperation('compress');
            } catch (error) {
                alert('Error compressing PDF: ' + error.message);
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
