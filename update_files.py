
import os

def update_company_vue():
    path = 'resources/js/Pages/Settings/Company.vue'
    if not os.path.exists(path):
        print(f"File not found: {path}")
        return

    with open(path, 'r') as f:
        content = f.read()

    # 1. Add Title, VISA, CREA
    target_crq = '<label class="block text-sm font-medium text-gray-700 mb-2">Registro CRQ (Conselho Regional de Química)</label>'
    new_fields = """              <div class="col-span-1 md:col-span-2 mt-4 mb-2 border-b pb-2">
                <h3 class="text-sm font-medium text-gray-900">Campos Personalizáveis do Certificados/Laudos</h3>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Registro VISA</label>
                <input v-model="form.register_visa" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" placeholder="">
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Registro no CREA</label>
                <input v-model="form.register_crea" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500" placeholder="">
              </div>

              <div>
                """ + target_crq
    
    if target_crq in content and "Registro VISA" not in content:
        content = content.replace('              <div>\n                ' + target_crq, new_fields)
        # Fallback if indentation is different
        if "Registro VISA" not in content:
             content = content.replace(target_crq, new_fields.replace('              <div>\n                ' + target_crq, target_crq))

    # 2. Update Manager Signature
    target_manager = '<label class="block text-sm font-medium text-gray-700 mb-4">Assinatura Gerente Operacional</label>'
    new_manager = """<label class="block text-sm font-medium text-gray-700 mb-4">Gerente Operacional</label>
                 
                 <div class="w-full mb-4">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Nome Completo</label>
                    <input v-model="form.operational_manager_name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="Nome do Gerente">
                 </div>
                 
                 <label class="block text-sm font-medium text-gray-700 mb-4">Assinatura Gerente Operacional</label>"""
    # Wait, the user wants "Gerente Operacional" as the main label, and the signature below.
    # My previous attempts removed "Assinatura Gerente Operacional" label potentially?
    # User said: "Gerente Operacional onde deve ser inserido o nome completo"
    # The existing field is a file input.
    # I should change the label to "Gerente Operacional", add the name input, and keep the file input (maybe labeling it "Assinatura").
    
    # Let's replace the Label line.
    new_manager_section = """<label class="block text-sm font-medium text-gray-700 mb-4">Gerente Operacional</label>
                 
                 <div class="w-full mb-4">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Nome Completo</label>
                    <input v-model="form.operational_manager_name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="Nome do Gerente">
                 </div>"""
    
    if target_manager in content:
        content = content.replace(target_manager, new_manager_section)

    # 3. Update Chemist Signature
    target_chemist = '<label class="block text-sm font-medium text-gray-700 mb-4">Assinatura Químico Responsável</label>'
    new_chemist_section = """<label class="block text-sm font-medium text-gray-700 mb-4">Responsável Técnico</label>

                 <div class="w-full mb-4">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Nome Completo</label>
                    <input v-model="form.technical_responsible_name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" placeholder="Nome do Responsável">
                 </div>"""

    if target_chemist in content:
        content = content.replace(target_chemist, new_chemist_section)

    with open(path, 'w') as f:
        f.write(content)
    print("Updated Company.vue")

def update_certificate_blade():
    path = 'resources/views/pdf/certificate.blade.php'
    if not os.path.exists(path):
        print(f"File not found: {path}")
        return

    with open(path, 'r') as f:
        content = f.read()

    # 1. Add VISA/CREA to Legal Info
    # Look for the closing row of the legal info table
    # We look for the licensed business block
    
    markup_business = """@if($company->license_business)
                        <strong>N° Alvará de Funcionamento: </strong>{{ $company->license_business }}
                    @endif
                </td>
            </tr>"""
            
    new_legal_row = """@if($company->license_business)
                        <strong>N° Alvará de Funcionamento: </strong>{{ $company->license_business }}
                    @endif
                </td>
            </tr>
            @if($company->register_visa || $company->register_crea)
                <tr>
                    <td class="col-33 text-center">
                        @if($company->register_visa)
                            <strong>Registro VISA: </strong>{{ $company->register_visa }}
                        @endif
                    </td>
                    <td class="col-33 text-center">
                        @if($company->register_crea)
                            <strong>Registro CREA: </strong>{{ $company->register_crea }}
                        @endif
                    </td>
                    <td class="col-33 text-center"></td>
                </tr>
            @endif"""

    if markup_business in content:
        content = content.replace(markup_business, new_legal_row)

    # 2. Update Signatures
    # Manager
    target_sig_manager = """                <div class="signature-line">
                    Gerente Operacional
                </div>"""
    new_sig_manager = """                <div class="signature-line">
                    {{ $company->operational_manager_name ? $company->operational_manager_name . ' - ' : '' }}Gerente Operacional
                </div>"""
        
    if target_sig_manager in content:
        content = content.replace(target_sig_manager, new_sig_manager)
        
    # Chemist
    target_sig_chemist = """                <div class="signature-line">
                    Químico Responsável
                    @if($company->crq)
                        <br>CRQ: {{ $company->crq }}
                    @endif
                </div>"""
    new_sig_chemist = """                <div class="signature-line">
                    {{ $company->technical_responsible_name ? $company->technical_responsible_name . ' - ' : '' }}Responsável Técnico
                    @if($company->crq)
                        <br>CRQ: {{ $company->crq }}
                    @endif
                </div>"""

    if "Químico Responsável" in content:
        # Fallback if the multiline match fails due to indentation
        # We can try to match just the text
        content = content.replace("Químico Responsável", "{{ $company->technical_responsible_name ? $company->technical_responsible_name . ' - ' : '' }}Responsável Técnico")
    
    if "Gerente Operacional" in content and "$company->operational_manager_name" not in content:
         # Be careful not to replace it if it's already done or somewhere else
         pass 

    # Re-applying precise match replacement for chemist to ensure structure
    if target_sig_chemist in content:
        content = content.replace(target_sig_chemist, new_sig_chemist)

    with open(path, 'w') as f:
        f.write(content)
    print("Updated certificate.blade.php")

if __name__ == "__main__":
    update_company_vue()
    update_certificate_blade()
