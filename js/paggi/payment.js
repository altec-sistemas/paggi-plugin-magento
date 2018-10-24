var paggi = {
    processing: false,
    invalidTaxvatNumbers: [
        '11111111111',
        '22222222222',
        '33333333333',
        '44444444444',
        '55555555555',
        '66666666666',
        '77777777777',
        '88888888888',
        '99999999999'
    ],

    identifyCcNumber: function (ccNumber) {
        ccNumber = ccNumber.replace(/\D/g, "");
        let creditCard = '';
        let visa = /^4[0-9]{12}(?:[0-9]{3})?$/;
        let master = /^((5[1-5][0-9]{14})$|^(2(2(?=([2-9]{1}[1-9]{1}))|7(?=[0-2]{1}0)|[3-6](?=[0-9])))[0-9]{14})$/;
        let amex = /^(34|37)\d{13}/;
        let elo = /^((((457393)|(431274)|(627780)|(636368)|(438935)|(504175)|(451416)|(636297))\d{0,10})|((5067)|(4576)|(4011))\d{0,12})$/;
        let discover = /^(6011|622\d{1}|(64|65)\d{2})\d{12}/;
        let hipercard = /^(606282\d{10}(\d{3})?)|^(3841\d{15})$/;
        let diners = /^((30(1|5))|(36|38)\d{1})\d{11}/;
        let jcb = /^(30[0-5][0-9]{13}|3095[0-9]{12}|35(2[8-9][0-9]{12}|[3-8][0-9]{13})|36[0-9]{12}|3[8-9][0-9]{14}|6011(0[0-9]{11}|[2-4][0-9]{11}|74[0-9]{10}|7[7-9][0-9]{10}|8[6-9][0-9]{10}|9[0-9]{11})|62(2(12[6-9][0-9]{10}|1[3-9][0-9]{11}|[2-8][0-9]{12}|9[0-1][0-9]{11}|92[0-5][0-9]{10})|[4-6][0-9]{13}|8[2-8][0-9]{12})|6(4[4-9][0-9]{13}|5[0-9]{14}))$/;
        let aura = /^50\d{14}/;

        if (elo.test(ccNumber)) {
            creditCard = 'EL';
        } else if (visa.test(ccNumber)) {
            creditCard = 'VI';
        } else if (master.test(ccNumber)) {
            creditCard = 'MC';
        } else if (amex.test(ccNumber)) {
            creditCard = 'AM';
        } else if (discover.test(ccNumber)) {
            creditCard = 'DI';
        } else if (diners.test(ccNumber)) {
            creditCard = 'DC';
        } else if (hipercard.test(ccNumber)) {
            creditCard = 'HI';
        } else if (jcb.test(ccNumber)) {
            creditCard = 'JC';
        } else if (aura.test(ccNumber)) {
            creditCard = 'AU';
        }

        return creditCard;
    },

    validateCreditCard: function (s) {
        // remove non-numerics
        let v = "0123456789";
        let w = "";
        for (i = 0; i < s.length; i++) {
            x = s.charAt(i);
            if (v.indexOf(x, 0) != -1)
                w += x;
        }
        // validate number
        j = w.length / 2;
        k = Math.floor(j);
        m = Math.ceil(j) - k;
        c = 0;
        for (i = 0; i < k; i++) {
            a = w.charAt(i * 2 + m) * 2;
            c += a > 9 ? Math.floor(a / 10 + a % 10) : a;
        }
        for (i = 0; i < k + m; i++) c += w.charAt(i * 2 + 1 - m) * 1;
        return (c % 10 == 0);
    },

    removeCard: function (url, customerId, confirmMessage) {
        let self = this;
        if (confirm(confirmMessage)) {
            if (!self.processing) {
                let card = $j('select#savedCard option:selected').val();
                if (card != '0') {
                    self.processing = true;
                    $j.ajax({
                        url: url,
                        type: "post",
                        dataType: 'json',
                        data: {
                            'cId': card,
                            'custId': customerId
                        }
                    }).success(function (response) {
                        if (response.code == '200') {
                            $j('select#savedCard option:selected').remove();
                        }
                        self.processing = false;
                    }).error(function () {
                        self.processing = false;
                    });
                }
            }
        }
    },

    validateTaxvat: function (taxvat) {
        taxvat = taxvat.replace(/\D/g, "");
        if (taxvat.length === 11) {
            let digits = this.checkCPF(taxvat.substring(0, 9));

            if (taxvat.substring(9, 11) == digits) {
                if (this.invalidTaxvatNumbers.indexOf(taxvat.toString()) === -1) {
                    return true;
                }
            }
        } else if (taxvat.length == 14){
            if (taxvat.substring(12, 14) == this.checkCNPJ(taxvat.substring(0, 12))) {
                return true;
            }
        }
        return false;
    },
    checkCNPJ: function (vCNPJ) {
        let mControl = "";
        let mControl1 = "";
        let mSum = "";
        let mDigit = "";
        let aTabCNPJ = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        for (let i = 1; i <= 2; i++) {
            mSum = 0;
            for (let j = 0; j < vCNPJ.length; j++) {
                mSum = mSum + (vCNPJ.substring(j, j + 1) * aTabCNPJ[j]);
            }

            if (i == 2)
                mSum = mSum + (2 * mDigit);

            mDigit = (mSum * 10) % 11;

            if (mDigit == 10)
                mDigit = 0;

            mControl1 = mControl;
            mControl = mDigit;
            aTabCNPJ = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3];
        }

        return ((mControl1 * 10) + mControl);
    },
    checkCPF: function (vCPF) {
        let mControl = "";
        let mControl1 = "";
        let mSum = "";
        let mContIni = 2, mContFim = 10, mDigit = 0;
        for (let j = 1; j <= 2; j++) {
            mSum = 0;
            for (let i = mContIni; i <= mContFim; i++) {
                mSum = mSum + (vCPF.substring((i - j - 1), (i - j)) * (mContFim + 1 + j - i));
            }
            if (j == 2)
                mSum = mSum + (2 * mDigit);

            mDigit = (mSum * 10) % 11;

            if (mDigit == 10)
                mDigit = 0;

            mControl1 = mControl;
            mControl = mDigit;
            mContIni = 3;
            mContFim = 11;
        }
        return ((mControl1 * 10) + mControl);
    }
};
