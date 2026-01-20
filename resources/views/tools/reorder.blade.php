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

        <div x-data="pdfReorderer()" class="space-y-6">
            <!-- File Upload -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select PDF file</label>
                <input type="file" @change="handleFile($event.target.files)" accept="application/pdf" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            </div>

            <!-- Page List with Drag and Drop -->
            <div x-show="totalPages > 0" class="space-y-4">
                <h3 class="font-semibold text-gray-900">Drag pages to reorder:</h3>
                <div class="space-y-2" x-ref="pageList">
                    <template x-for="(page, index) in pageOrder" :key="index">
                        <div draggable="true"
                             @dragstart="dragStart(index, $event)"
                             @dragover.prevent="dragOver(index, $event)"
                             @drop.prevent="drop(index, $event)"
                             @dragend="dragEnd()"
                             class="flex items-center justify-between bg-gray-50 p-3 rounded cursor-move hover:bg-gray-100"
                             :class="draggedIndex === index ? 'opacity-50' : ''">
                            <span class="text-sm text-gray-700">Page <span x-text="page"></span></span>
                            <div class="flex space-x-2">
                                <button @click="moveUp(index)" :disabled="index === 0" class="text-indigo-600 hover:text-indigo-800 disabled:text-gray-400">↑</button>
                                <button @click="moveDown(index)" :disabled="index === pageOrder.length - 1" class="text-indigo-600 hover:text-indigo-800 disabled:text-gray-400">↓</button>
                            </div>
                        </div>
                    </template>
                </div>
                
                <button @click="resetOrder()" class="text-sm text-gray-600 hover:text-gray-800">Reset to original order</button>
            </div>

            <!-- Process Button -->
            <button @click="reorderPdf()" 
                    :disabled="!file || processing"
                    class="w-full bg-indigo-600 text-white py-3 px-4 rounded-lg font-semibold hover:bg-indigo-700 disabled:bg-gray-400 disabled:cursor-not-allowed"
                    x-text="processing ? 'Processing...' : 'Reorder PDF'">
            </button>

            <!-- Download Link -->
            <div x-show="downloadUrl" class="text-center">
                <a :href="downloadUrl" :download="downloadFilename" 
                   class="inline-block bg-green-600 text-white py-3 px-6 rounded-lg font-semibold hover:bg-green-700">
                    Download Reordered PDF
                </a>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('js/pdf-tools.js') }}"></script>
<script>
function pdfReorderer() {
    return {
        file: null,
        totalPages: 0,
        pageOrder: [],
        originalOrder: [],
        draggedIndex: null,
        processing: false,
        downloadUrl: null,
        downloadFilename: 'reordered.pdf',

        async handleFile(fileList) {
            if (fileList.length === 0) return;
            this.file = fileList[0];
            try {
                const pdfDoc = await PDFLib.PDFDocument.load(await this.file.arrayBuffer());
                this.totalPages = pdfDoc.getPageCount();
                this.pageOrder = Array.from({ length: this.totalPages }, (_, i) => i + 1);
                this.originalOrder = [...this.pageOrder];
            } catch (error) {
                alert('Error loading PDF: ' + error.message);
            }
        },

        resetOrder() {
            this.pageOrder = [...this.originalOrder];
        },

        dragStart(index, event) {
            this.draggedIndex = index;
            event.dataTransfer.effectAllowed = 'move';
        },

        dragOver(index, event) {
            if (this.draggedIndex === null) return;
            event.dataTransfer.dropEffect = 'move';
        },

        drop(index, event) {
            if (this.draggedIndex === null) return;
            const draggedItem = this.pageOrder[this.draggedIndex];
            this.pageOrder.splice(this.draggedIndex, 1);
            this.pageOrder.splice(index, 0, draggedItem);
            this.draggedIndex = null;
        },

        dragEnd() {
            this.draggedIndex = null;
        },

        moveUp(index) {
            if (index === 0) return;
            [this.pageOrder[index], this.pageOrder[index - 1]] = [this.pageOrder[index - 1], this.pageOrder[index]];
        },

        moveDown(index) {
            if (index === this.pageOrder.length - 1) return;
            [this.pageOrder[index], this.pageOrder[index + 1]] = [this.pageOrder[index + 1], this.pageOrder[index]];
        },

        async reorderPdf() {
            if (!this.file) return;
            
            this.processing = true;
            try {
                const result = await reorderPdf(this.file, this.pageOrder);
                this.downloadUrl = result.url;
                this.downloadFilename = result.filename;
                
                await this.trackOperation('reorder');
            } catch (error) {
                alert('Error reordering PDF: ' + error.message);
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
